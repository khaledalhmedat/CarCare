# syntax=docker/dockerfile:1
FROM php:8.2-cli-alpine AS base

RUN --mount=type=cache,target=/var/cache/apk \
    apk add --no-cache \
        icu-dev \
        oniguruma-dev \
        libzip-dev \
        linux-headers \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        git \
        unzip \
        dos2unix \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        pdo_mysql \
        bcmath \
        opcache \
        zip \
        pcntl \
        gd \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2.6.5 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN dos2unix /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh

FROM base AS vendor
WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/root/.composer/cache,id=laravel-template,sharing=locked \
    composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-autoloader

COPY . .
RUN --mount=type=cache,target=/root/.composer/cache,id=laravel-template,sharing=locked \
    composer dump-autoload --optimize --classmap-authoritative --no-interaction

FROM base AS production
WORKDIR /var/www/html

COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html

RUN mkdir -p storage bootstrap/cache && \
    chown -R www-data:www-data storage bootstrap/cache && \
    chmod -R 775 storage bootstrap/cache

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
EXPOSE 9000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=9000"]
