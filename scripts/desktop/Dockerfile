FROM node:22-bookworm-slim AS node
FROM composer:2.8 AS composer
FROM php:8.4-cli-bookworm

ARG YT_DLP_VERSION=2026.8.19
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev libicu-dev libonig-dev libzip-dev unzip ffmpeg python3 python3-venv supervisor curl \
    && docker-php-ext-install pdo_pgsql pcntl intl mbstring zip \
    && python3 -m venv /opt/media \
    && /opt/media/bin/pip install --no-cache-dir "yt-dlp[default]==${YT_DLP_VERSION}" \
    && ln -s /opt/media/bin/yt-dlp /usr/local/bin/yt-dlp \
    && printf '%s\n' '--js-runtimes node' > /etc/yt-dlp.conf \
    && rm -rf /var/lib/apt/lists/*
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /opt/transcribe/app/backend
COPY app/backend/composer.json app/backend/composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-progress
COPY app/backend/artisan ./
COPY app/backend/app ./app
COPY app/backend/bootstrap ./bootstrap
COPY app/backend/config ./config
COPY app/backend/database ./database
COPY app/backend/public ./public
COPY app/backend/resources ./resources
COPY app/backend/routes ./routes
COPY packages/contracts/languages.json /opt/transcribe/packages/contracts/languages.json
COPY packages/localization /opt/transcribe/packages/localization
COPY scripts/desktop/entrypoint.sh scripts/desktop/supervisor-config.php scripts/desktop/healthcheck.sh /opt/transcribe/
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && chmod +x /opt/transcribe/entrypoint.sh /opt/transcribe/healthcheck.sh \
    && chown -R www-data:www-data storage bootstrap/cache
COPY --from=node /usr/local/LICENSE /usr/share/doc/transcribe/node-LICENSE
USER www-data
EXPOSE 8001
ENTRYPOINT ["/opt/transcribe/entrypoint.sh"]
CMD ["serve"]
