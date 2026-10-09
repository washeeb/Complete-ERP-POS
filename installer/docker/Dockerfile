# Synclytics ERP — server image for cloud / head-office servers (setups C and D).
# Build from the folder that holds `server/` (and `pos/` for source checkouts):
#   docker build -f installer/docker/Dockerfile -t synclytics-erp .
# A release ZIP already contains the built till app in server/public/pos; a source checkout builds it here.

# 1. Front-end assets: the till app (from pos/, when present) and the back office's own assets.
FROM node:24-bookworm-slim AS assets
WORKDIR /src
COPY . .
RUN set -eux; \
    if [ -f pos/package.json ] && [ ! -f server/public/pos/index.html ]; then \
        cd pos && npm ci --no-audit --no-fund && POS_OUT_DIR=/src/server/public/pos npm run build:server && cd ..; \
    fi; \
    if [ -f server/package.json ] && [ ! -f server/public/build/manifest.json ]; then \
        cd server && npm ci --no-audit --no-fund && npm run build && rm -rf node_modules && cd ..; \
    fi; \
    test -f server/public/pos/index.html

# 2. PHP libraries (no development tools).
FROM composer:2 AS vendor
WORKDIR /app
COPY --from=assets /src/server /app
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress --ignore-platform-req=ext-intl --ignore-platform-req=ext-bcmath --ignore-platform-req=ext-pdo_pgsql --ignore-platform-req=ext-gd

# 3. Runtime: PHP-FPM + nginx + the scheduler, live updates and queue worker under supervisord.
FROM php:8.4-fpm-bookworm
ARG PG_MAJOR=17
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends nginx supervisor curl ca-certificates gnupg libicu-dev libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libicu72 libpq5 libzip4 libpng16-16 libjpeg62-turbo libfreetype6; \
    install -d /usr/share/postgresql-common/pgdg; \
    curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc; \
    echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" > /etc/apt/sources.list.d/pgdg.list; \
    apt-get update; apt-get install -y --no-install-recommends postgresql-client-${PG_MAJOR}; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql intl bcmath zip gd opcache pcntl; \
    apt-get purge -y --auto-remove libicu-dev libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev gnupg; \
    rm -rf /var/lib/apt/lists/*
COPY installer/docker/php.ini /usr/local/etc/php/conf.d/synclytics.ini
COPY installer/docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-synclytics.conf
COPY installer/docker/nginx.conf /etc/nginx/sites-available/default
COPY installer/docker/supervisord.conf /etc/supervisor/conf.d/synclytics.conf
COPY installer/docker/entrypoint.sh /usr/local/bin/synclytics-entrypoint
WORKDIR /var/www/server
COPY --from=vendor --chown=www-data:www-data /app /var/www/server
RUN chmod +x /usr/local/bin/synclytics-entrypoint && rm -f .env && mkdir -p storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
ENV BACKUP_PG_BIN=/usr/lib/postgresql/${PG_MAJOR}/bin
EXPOSE 80 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s CMD curl -fs http://localhost/up || exit 1
ENTRYPOINT ["synclytics-entrypoint"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
