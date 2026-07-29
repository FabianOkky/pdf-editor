#!/usr/bin/env sh
set -e

cd /var/www/html

# Ensure the writable directories exist (named volumes start empty).
mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    storage/app/pdfs \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Rebuild the package manifest now that the environment is available.
php artisan package:discover --ansi || true

# Cache config/routes/views for production (safe to re-run; ignored if it fails in dev).
if [ "${APP_ENV}" = "production" ]; then
    php artisan config:cache || true
    php artisan route:cache || true
    php artisan view:cache || true
fi

exec "$@"
