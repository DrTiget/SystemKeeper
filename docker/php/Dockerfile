FROM php:8.5-fpm

ARG UID=1000
ARG GID=1000

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip openssh-client default-mysql-client gzip \
        libzip-dev libicu-dev libonig-dev \
    && docker-php-ext-install pdo_mysql zip intl bcmath pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

RUN groupmod -o -g ${GID} www-data && usermod -o -u ${UID} -g ${GID} www-data

WORKDIR /var/www/html
USER www-data
