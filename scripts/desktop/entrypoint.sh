#!/bin/sh
set -eu
cd /opt/transcribe/app/backend
case "${1:-serve}" in
    migrate) exec php artisan migrate --force --no-ansi ;;
    serve)
        php artisan subtitles:runtime-check --json --strict --no-ansi | php /opt/transcribe/supervisor-config.php > /tmp/supervisord.conf
        exec supervisord -c /tmp/supervisord.conf
        ;;
    *) exec "$@" ;;
esac
