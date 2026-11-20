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

# Base PHP 8.4 runtime built from precompiled Alpine packages — avoids PECL network
# failures (HTTP 500) and slow C extension / llvm-clang source builds during release_create.
FROM alpine:3.24 AS php-base
RUN apk add --no-cache \
    wget \
    php84 \
    php84-cli \
    php84-ctype \
    php84-curl \
    php84-dom \
    php84-fileinfo \
    php84-iconv \
    php84-intl \
    php84-json \
    php84-mbstring \
    php84-opcache \
    php84-openssl \
    php84-pdo \
    php84-pdo_pgsql \
    php84-phar \
    php84-posix \
    php84-session \
    php84-simplexml \
    php84-sodium \
    php84-tokenizer \
    php84-xml \
    php84-xmlreader \
    php84-xmlwriter \
    php84-zip \
  && ln -sf /usr/bin/php84 /usr/bin/php

# Test stage: Pest + pcov. Platform builds --target test during release_create.
FROM php-base AS test
RUN apk add --no-cache php84-pdo_sqlite php84-sqlite3 php84-pecl-pcov
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

FROM php-base
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
