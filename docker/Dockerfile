# syntax=docker/dockerfile:1.7
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts

FROM php:8.3-fpm-alpine AS runtime
RUN apk add --no-cache fcgi \
 && docker-php-ext-install pdo_mysql opcache \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/99-app.ini"
WORKDIR /var/www
COPY --from=vendor /app/vendor ./vendor
COPY src ./src
COPY public ./public
COPY migrations ./migrations
COPY bin ./bin
RUN mkdir -p var && chown -R www-data:www-data var
USER www-data

