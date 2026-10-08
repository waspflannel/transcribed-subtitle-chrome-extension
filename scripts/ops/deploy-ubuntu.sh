#!/usr/bin/env bash
# Provision and deploy Transcribe on Ubuntu (EC2 or similar).
# Upload the repository root, not only app/backend. Localization and
# language catalogs live in packages/ and are required at runtime.
#
# This is the first-boot host script. It is not an Artisan command.
# `composer setup` only installs PHP deps and migrates locally.
# `scripts/ops/deploy-managed-laravel.ps1` assumes PHP, Composer,
# Nginx, ffmpeg, and yt-dlp are already on the host.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND="$ROOT/app/backend"
PHP_VERSION="${PHP_VERSION:-8.4}"
APP_USER="${APP_USER:-}"
DOMAIN="${DOMAIN:-}"
SKIP_MIGRATE=0
COMMAND=""

usage() {
    cat <<'EOF'
Usage:
  sudo ./scripts/ops/deploy-ubuntu.sh provision [--user NAME] [--domain example.com]
  sudo ./scripts/ops/deploy-ubuntu.sh deploy [--user NAME] [--skip-migrate]

provision  Install PHP, Composer, Nginx, Supervisor, ffmpeg, and yt-dlp.
deploy     Install PHP dependencies, migrate, optimize, and restart workers.

Upload this whole repository to the server. The website translations and
language list are outside app/backend. The Chrome extension is not installed
here; build that ZIP on your computer after https://YOUR_DOMAIN/v1 works.

Before deploy, copy app/backend/.env.example to app/backend/.env and set
production values, including APP_URL, database, Redis, and provider keys.
The default worker pool is 31 processes. Lower SUBTITLE_*_WORKERS in .env
on a small instance before the first deploy.
EOF
}

log() {
    printf '%s\n' "$*"
}

die() {
    printf 'Error: %s\n' "$*" >&2
    exit 1
}

need_cmd() {
    command -v "$1" >/dev/null 2>&1 || die "Missing required command: $1"
}

parse_args() {
    COMMAND="${1:-}"
    shift || true

    while [[ $# -gt 0 ]]; do
        case "$1" in
            --user)
                APP_USER="${2:-}"
                [[ -n "$APP_USER" ]] || die "--user requires a name."
                shift 2
                ;;
            --domain)
                DOMAIN="${2:-}"
                [[ -n "$DOMAIN" ]] || die "--domain requires a hostname."
                shift 2
                ;;
            --skip-migrate)
                SKIP_MIGRATE=1
                shift
                ;;
            -h|--help)
                usage
                exit 0
                ;;
            *)
                die "Unknown option: $1"
                ;;
        esac
    done

    [[ -n "$COMMAND" ]] || { usage; exit 1; }
}

require_repo() {
    [[ -f "$BACKEND/artisan" ]] || die "Laravel app not found at $BACKEND"
    [[ -f "$ROOT/packages/localization/locales.json" ]] || die "Upload the repository root. Missing packages/localization."
    [[ -f "$ROOT/packages/contracts/languages.json" ]] || die "Upload the repository root. Missing packages/contracts."
}

detect_app_user() {
    if [[ -z "$APP_USER" ]]; then
        APP_USER="$(stat -c '%U' "$BACKEND")"
        if [[ "$APP_USER" == "root" ]]; then
            die "Repo is owned by root. Pass --user ubuntu (or your SSH user)."
        fi
    fi
    id "$APP_USER" >/dev/null 2>&1 || die "Application user '$APP_USER' does not exist."
}

php_bin() {
    if command -v "php${PHP_VERSION}" >/dev/null 2>&1; then
        command -v "php${PHP_VERSION}"
        return
    fi
    command -v php
}

php_has_package() {
    apt-cache show "$1" >/dev/null 2>&1
}

ubuntu_codename() {
    . /etc/os-release
    printf '%s\n' "${VERSION_CODENAME:-}"
}

remove_broken_php_ppa() {
    rm -f /etc/apt/sources.list.d/ondrej-ubuntu-php-*.list \
        /etc/apt/sources.list.d/ondrej-ubuntu-php-*.sources
}

enable_ubuntu_universe() {
    local file
    for file in /etc/apt/sources.list /etc/apt/sources.list.d/ubuntu.sources /etc/apt/sources.list.d/*.sources; do
        [[ -f "$file" ]] || continue
        if grep -q '^Components:' "$file" && ! grep -qE '^Components:.*\buniverse\b' "$file"; then
            sed -i 's/^Components:\(.*\)/Components:\1 universe/' "$file"
        fi
    done
    add-apt-repository -y universe || true

    if php_has_package php8.5-fpm || php_has_package php-fpm; then
        return
    fi

    log "Refreshing apt lists so universe PHP packages appear."
    rm -rf /var/lib/apt/lists/*
    mkdir -p /var/lib/apt/lists/partial
    apt-get update -y
}

php_extensions() {
    local prefix="$1"
    local packages=()
    local name
    for name in cli fpm bcmath curl gd intl mbstring pgsql xml zip; do
        packages+=("${prefix}${name}")
    done
    if php_has_package "${prefix}redis"; then
        packages+=("${prefix}redis")
    else
        packages+=("php-redis")
    fi
    printf '%s\n' "${packages[@]}"
}

select_php_version() {
    local candidate
    for candidate in "$PHP_VERSION" 8.5 8.4 8.3; do
        if php_has_package "php${candidate}-fpm"; then
            PHP_VERSION="$candidate"
            log "Using PHP ${PHP_VERSION}."
            return
        fi
    done

    if php_has_package php-fpm; then
        PHP_VERSION=""
        log "Using the Ubuntu php-fpm metapackage."
        return
    fi

    local codename
    codename="$(ubuntu_codename)"
    case "$codename" in
        jammy|noble|oracular|plucky|questing)
            add-apt-repository -y ppa:ondrej/php
            apt-get update -y
            php_has_package "php${PHP_VERSION:-8.4}-fpm" || die "php-fpm is still not available after adding the PHP PPA."
            PHP_VERSION="${PHP_VERSION:-8.4}"
            ;;
        *)
            die "No php-fpm package is available. Check that universe is in /etc/apt/sources.list.d/ubuntu.sources, then run: apt-cache search php-fpm"
            ;;
    esac
}

detect_installed_php_version() {
    if [[ -n "$PHP_VERSION" ]]; then
        return
    fi

    PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"
    [[ -n "$PHP_VERSION" ]] || die "Could not detect the installed PHP version."
    log "Detected PHP ${PHP_VERSION}."
}

require_env() {
    [[ -f "$BACKEND/.env" ]] || die "Create $BACKEND/.env from .env.example before deploy."

    if grep -Eq '^APP_URL=https?://(localhost|127\.0\.0\.1)' "$BACKEND/.env"; then
        die "APP_URL in $BACKEND/.env still points at localhost. Set the public HTTPS origin."
    fi
}

install_php() {
    enable_ubuntu_universe
    select_php_version

    local prefix
    if [[ -n "$PHP_VERSION" ]]; then
        prefix="php${PHP_VERSION}-"
    else
        prefix="php-"
    fi

    # shellcheck disable=SC2046
    DEBIAN_FRONTEND=noninteractive apt-get install -y $(php_extensions "$prefix")
    detect_installed_php_version
}

configure_php_fpm() {
    local pool="/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"
    [[ -f "$pool" ]] || die "PHP-FPM pool not found at $pool"

    sed -i "s/^user = .*/user = ${APP_USER}/" "$pool"
    sed -i "s/^group = .*/group = ${APP_USER}/" "$pool"
    systemctl enable --now "php${PHP_VERSION}-fpm"
    systemctl restart "php${PHP_VERSION}-fpm"
}

install_composer() {
    if command -v composer >/dev/null 2>&1; then
        return
    fi

    local installer
    installer="$(mktemp)"
    curl -fsSL https://getcomposer.org/installer -o "$installer"
    "$(php_bin)" "$installer" --install-dir=/usr/local/bin --filename=composer
    rm -f "$installer"
}

install_yt_dlp() {
    if command -v yt-dlp >/dev/null 2>&1; then
        return
    fi

    curl -fsSL https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp \
        -o /usr/local/bin/yt-dlp
    chmod 755 /usr/local/bin/yt-dlp
}

write_nginx_site() {
    local server_name="${DOMAIN:-_}"
    local conf="/etc/nginx/sites-available/transcribe"
    cat > "$conf" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${server_name};
    root ${BACKEND}/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;
    client_max_body_size 32M;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
    }

    location ~ /\\.(?!well-known).* {
        deny all;
    }
}
EOF

    ln -sfn "$conf" /etc/nginx/sites-enabled/transcribe
    rm -f /etc/nginx/sites-enabled/default
    nginx -t
    systemctl enable --now nginx
    systemctl reload nginx

    if [[ -n "$DOMAIN" ]]; then
        log "Nginx is serving ${DOMAIN} from ${BACKEND}/public over HTTP."
        log "After DNS works, run: sudo certbot --nginx -d ${DOMAIN}"
    else
        log "Nginx is serving ${BACKEND}/public on port 80. Pass --domain later for a named vhost and TLS."
    fi
}

write_supervisor() {
    local php
    php="$(php_bin)"
    local log_dir="/var/log/transcribe"
    local output="$BACKEND/storage/ops/transcribe-workers.conf"
    mkdir -p "$(dirname "$output")" "$log_dir"
    chown "$APP_USER":"$APP_USER" "$log_dir" || true

    local total
    total="$(
        cd "$BACKEND"
        "$php" artisan subtitles:runtime-check --json --no-ansi | "$php" -r '
$root = $argv[1];
$php = $argv[2];
$user = $argv[3];
$logDir = $argv[4];
$output = $argv[5];
$runtime = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$groups = $runtime["summary"]["subtitleWorkerGroups"] ?? [];
if ($groups === []) {
    fwrite(STDERR, "No subtitle worker groups were reported.\n");
    exit(1);
}
$lines = [
    "; Generated by scripts/ops/deploy-ubuntu.sh",
    "; Installed to /etc/supervisor/conf.d/transcribe-workers.conf",
    "",
];
$total = 0;
foreach ($groups as $group) {
    $name = $group["name"];
    $count = (int) $group["worker_count"];
    $total += $count;
    $queues = implode(",", $group["queues"]);
    $connection = $group["connection"];
    $timeout = (int) $group["timeout_seconds"];
    $lines[] = "[program:tse-{$name}]";
    $lines[] = "process_name=%(program_name)s_%(process_num)02d";
    $lines[] = "directory={$root}";
    $lines[] = "command={$php} artisan queue:work {$connection} --queue={$queues} --sleep=0 --tries=0 --timeout={$timeout} --memory=256 --max-time=3600";
    $lines[] = "autostart=true";
    $lines[] = "autorestart=true";
    $lines[] = "stopasgroup=true";
    $lines[] = "killasgroup=true";
    $lines[] = "user={$user}";
    $lines[] = "numprocs={$count}";
    $lines[] = "redirect_stderr=true";
    $lines[] = "stdout_logfile={$logDir}/tse-{$name}.log";
    $lines[] = "stopwaitsecs=".($timeout + 60);
    $lines[] = "";
}
file_put_contents($output, implode("\n", $lines));
echo $total;
' "$BACKEND" "$php" "$APP_USER" "$log_dir" "$output"
    )"

    if [[ -d /etc/supervisor/conf.d ]]; then
        cp "$output" /etc/supervisor/conf.d/transcribe-workers.conf
        supervisorctl reread
        supervisorctl update
        supervisorctl restart 'tse-*:*' || supervisorctl start 'tse-*:*'
    fi

    log "Supervisor will run ${total} worker processes from $output"
}

install_scheduler() {
    local cron_file="/etc/cron.d/transcribe"
    cat > "$cron_file" <<EOF
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
* * * * * ${APP_USER} cd ${BACKEND} && $(php_bin) artisan schedule:run >> /dev/null 2>&1
EOF
    chmod 644 "$cron_file"
    systemctl enable --now cron
}

provision() {
    [[ "$(id -u)" -eq 0 ]] || die "provision must run as root (sudo)."
    require_repo
    detect_app_user
    remove_broken_php_ppa
    apt-get update -y
    DEBIAN_FRONTEND=noninteractive apt-get install -y software-properties-common
    enable_ubuntu_universe
    DEBIAN_FRONTEND=noninteractive apt-get install -y \
        ca-certificates \
        curl \
        git \
        unzip \
        nginx \
        supervisor \
        ffmpeg \
        cron
    if [[ -n "$DOMAIN" ]]; then
        DEBIAN_FRONTEND=noninteractive apt-get install -y certbot python3-certbot-nginx
    fi
    install_php
    configure_php_fpm
    install_composer
    install_yt_dlp
    install_scheduler
    systemctl enable --now supervisor
    write_nginx_site
    log "Provision finished. Edit $BACKEND/.env, then run:"
    log "  sudo $ROOT/scripts/ops/deploy-ubuntu.sh deploy --user $APP_USER"
}

deploy() {
    require_repo
    detect_app_user
    require_env
    need_cmd composer
    local php
    php="$(php_bin)"

    if [[ ! -x "$(command -v ffmpeg)" ]]; then
        die "ffmpeg is not on PATH. Run provision first."
    fi
    if [[ ! -x "$(command -v yt-dlp)" ]]; then
        die "yt-dlp is not on PATH. Run provision first."
    fi

    (
        cd "$BACKEND"
        composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
        "$php" artisan config:clear --no-ansi
        "$php" artisan instance:ensure-key --no-interaction --no-ansi
        if [[ "$SKIP_MIGRATE" -eq 0 ]]; then
            "$php" artisan migrate --force --no-ansi
        fi
        "$php" artisan optimize --no-ansi
        "$php" artisan queue:restart --no-ansi
        "$php" artisan storage:link --force --no-ansi || true
    )

    mkdir -p "$BACKEND/storage/logs" "$BACKEND/storage/app" "$BACKEND/bootstrap/cache"
    chown -R "$APP_USER":"$APP_USER" "$BACKEND/storage" "$BACKEND/bootstrap/cache"
    find "$BACKEND/storage" "$BACKEND/bootstrap/cache" -type d -exec chmod 775 {} +
    find "$BACKEND/storage" "$BACKEND/bootstrap/cache" -type f -exec chmod 664 {} + || true

    if [[ "$(id -u)" -eq 0 ]]; then
        if ! write_supervisor; then
            log "App files are deployed, but workers were not refreshed. Check Redis and rerun deploy."
        fi
    else
        log "Run this as root to refresh Supervisor workers:"
        log "  sudo $ROOT/scripts/ops/deploy-ubuntu.sh deploy --user $APP_USER"
    fi

    log "Deploy finished. Check https://YOUR_DOMAIN/up after DNS and TLS are set."
}

main() {
    parse_args "$@"

    case "$COMMAND" in
        provision)
            provision
            ;;
        deploy)
            deploy
            ;;
        -h|--help|help)
            usage
            ;;
        *)
            usage
            die "Unknown command: $COMMAND"
            ;;
    esac
}

main "$@"
