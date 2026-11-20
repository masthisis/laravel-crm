# syntax=docker/dockerfile:1

ARG PHP_VERSION=8.3

# ------------------------------------------------------------------
# base — PHP-FPM with every extension the CRM needs
# ------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS base

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions pdo_pgsql pdo_mysql bcmath intl zip opcache pcntl \
    && apk add --no-cache bash fcgi

WORKDIR /var/www/html

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ------------------------------------------------------------------
# dev — dependencies incl. dev packages, code is bind-mounted
# ------------------------------------------------------------------
FROM base AS dev

ENV APP_ENV=local
ENV WAIT_FOR_DB=true

COPY composer.json composer.lock* ./

RUN composer install --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist \
    || composer update --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist

COPY . .
RUN chown -R www-data:www-data storage bootstrap/cache

ENTRYPOINT ["./docker/entrypoint.sh"]
CMD ["php-fpm"]

# ------------------------------------------------------------------
# app — production php-fpm image (also used for workers/scheduler)
# ------------------------------------------------------------------
FROM base AS app

ENV APP_ENV=production
ENV APP_DEBUG=false

COPY composer.json composer.lock* ./

RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist \
    || composer update --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist

COPY . .

RUN composer dump-autoload --optimize --no-dev --classmap-authoritative \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh

ENTRYPOINT ["./docker/entrypoint.sh"]
CMD ["php-fpm"]

# ------------------------------------------------------------------
# nginx — web server in front of php-fpm
# ------------------------------------------------------------------
FROM nginx:1.27-alpine AS nginx

COPY --from=app /var/www/html/public /var/www/html/public
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

EXPOSE 80
