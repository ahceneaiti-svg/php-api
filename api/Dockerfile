# syntax=docker/dockerfile:1

FROM dunglas/frankenphp:1-php8.3

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SERVER_NAME=:8080 \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

# PHP extensions: redis client + prod niceties.
RUN install-php-extensions redis opcache intl zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Resolve dependencies first for better layer caching (no committed lock file).
COPY composer.json ./
RUN composer update --no-dev --prefer-dist --optimize-autoloader --no-progress

# Application code.
COPY . .

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && mkdir -p var/cache var/log \
    && php bin/console cache:clear --no-warmup \
    && php bin/console cache:warmup \
    && chown -R www-data:www-data var

EXPOSE 8080

HEALTHCHECK --interval=15s --timeout=3s --start-period=20s --retries=5 \
    CMD curl -fsS http://127.0.0.1:8080/health/live || exit 1

# Base image ENTRYPOINT already launches FrankenPHP (serves ./public).
