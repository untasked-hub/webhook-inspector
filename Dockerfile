# syntax=docker/dockerfile:1
# Multi-stage Laravel 13 + Vue/Inertia image for Untasked (Fly ~512MB).
FROM composer:2 AS vendor
WORKDIR /app
# Writable dirs required before any artisan / package:discover scripts.
RUN mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
  && chmod -R 777 bootstrap/cache storage
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --ignore-platform-reqs \
  || composer update --no-dev --no-scripts --no-interaction --prefer-dist --ignore-platform-reqs
COPY . .
RUN mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
  && chmod -R 777 bootstrap/cache storage \
  && composer dump-autoload --optimize --no-interaction

FROM composer:2 AS vendor-dev
WORKDIR /app
RUN mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
  && chmod -R 777 bootstrap/cache storage
COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-interaction --prefer-dist --ignore-platform-reqs \
  || composer update --no-scripts --no-interaction --prefer-dist --ignore-platform-reqs
COPY . .
RUN mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
  && chmod -R 777 bootstrap/cache storage \
  && composer dump-autoload --optimize --no-interaction

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --prefer-offline || npm install
COPY . .
COPY --from=vendor /app/vendor ./vendor
# Fail the image build when Vite fails — an empty manifest ships a blank browser shell.
RUN npm run build

# Test stage: Pest + pcov. Platform builds --target test during release_create.
FROM php:8.4-cli-alpine AS test
RUN apk add --no-cache postgresql-libs icu-libs libzip sqlite-libs libcurl \
  && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev icu-dev libzip-dev sqlite-dev curl-dev \
  && docker-php-ext-install pdo_pgsql pdo_sqlite intl zip curl \
  && pecl install pcov \
  && docker-php-ext-enable pcov \
  && apk del .build-deps
WORKDIR /app
COPY --from=vendor-dev /app /app
COPY --from=assets /app/public/build /app/public/build
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
  && chmod -R 777 storage bootstrap/cache
ENV APP_ENV=testing \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=:memory:
CMD ["php", "-d", "pcov.enabled=1", "vendor/bin/pest", "--colors=never", "--coverage", "--min=80", "--coverage-text"]

FROM php:8.4-cli-alpine
RUN apk add --no-cache postgresql-libs icu-libs libzip libcurl wget \
  && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev icu-dev libzip-dev curl-dev \
  && docker-php-ext-install pdo_pgsql intl zip opcache curl \
  && apk del .build-deps
WORKDIR /app
COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh \
  && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
  && chmod -R 777 storage bootstrap/cache
ENV APP_NAME="Webhook Inspector" \
    APP_ENV=production \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=pgsql \
    QUEUE_CONNECTION=database
EXPOSE 8080
HEALTHCHECK --interval=10s --timeout=3s --retries=5 CMD wget -qO- http://127.0.0.1:8080/up || exit 1
ENTRYPOINT ["/entrypoint.sh"]
