#!/bin/sh
set -eu
mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chmod -R 777 bootstrap/cache storage 2>/dev/null || true
# Local previews persist the encryption key in a volume. Production requires an externally supplied secret.
if [ -z "${APP_KEY:-}" ] && [ "${APP_ENV:-production}" = "local" ]; then
  mkdir -p /var/lib/webhook-inspector
  if [ ! -s /var/lib/webhook-inspector/app-key ]; then
    umask 077
    php -r 'echo "base64:".base64_encode(random_bytes(32));' > /var/lib/webhook-inspector/app-key
  fi
  APP_KEY="$(cat /var/lib/webhook-inspector/app-key)"
  export APP_KEY
fi
php artisan package:discover --ansi --no-interaction || true
# Platform does not execute release_command — migrate here with the migrator role.
# Fail loudly so a missing sessions/users table shows up in app_logs instead of a silent 500.
if [ -n "${MIGRATION_DATABASE_URL:-}" ]; then
  DB_URL="$MIGRATION_DATABASE_URL" php artisan migrate --force --no-interaction
fi
php artisan config:cache || true
php artisan route:cache || true
# Run the database worker and retention scheduler alongside the web process.
# A durable APP_KEY is mandatory outside local previews.
if [ -z "${APP_KEY:-}" ]; then
  echo 'APP_KEY is required; refusing to start.' >&2
  exit 1
fi
php artisan queue:work --sleep=2 --tries=5 --timeout=30 &
php artisan schedule:work &
# Prefer built-in server for the slim footprint; swap to frankenphp/php-fpm if measured better.
# Use docker/router.php (not public/index.php) so existing files under public/ are served as-is.
exec php -S 0.0.0.0:8080 -t public docker/router.php
