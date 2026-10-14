# Прод-образ SystemKeeper: API (php-fpm + nginx), собранный фронтенд и воркеры.
# Роль контейнера задаётся командой: web | queue | backups | scheduler (см. entrypoint.sh).

FROM node:22-alpine AS frontend
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

FROM php:8.5-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx supervisor curl openssh-client default-mysql-client postgresql-client zstd gzip iputils-ping \
        libzip-dev libicu-dev libonig-dev \
    && docker-php-ext-install pdo_mysql zip intl bcmath pcntl \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/prod/php-prod.ini /usr/local/etc/php/conf.d/zz-prod.ini
COPY docker/prod/nginx.conf /etc/nginx/conf.d/systemkeeper.conf
COPY docker/prod/supervisord.conf /etc/supervisor/conf.d/systemkeeper.conf
COPY docker/prod/entrypoint.sh /usr/local/bin/systemkeeper-entrypoint

WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app /var/www/html
COPY --from=frontend /app/dist /var/www/frontend

RUN php artisan package:discover --ansi \
    && rm -rf storage && mkdir -p /var/www/storage-skel/app/backups /var/www/storage-skel/framework/cache \
        /var/www/storage-skel/framework/sessions /var/www/storage-skel/framework/views /var/www/storage-skel/logs \
    && chown -R www-data:www-data bootstrap/cache

ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION} APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr

VOLUME /var/www/html/storage
EXPOSE 80
# Для воркеров (queue, backups, scheduler) проверка отключается в compose.yaml
HEALTHCHECK --interval=10s --timeout=5s --start-period=120s --retries=5 \
    CMD curl -fsS http://127.0.0.1/up >/dev/null || exit 1

ENTRYPOINT ["systemkeeper-entrypoint"]
CMD ["web"]
