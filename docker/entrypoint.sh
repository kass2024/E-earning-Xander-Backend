#!/bin/sh
set -e

cd /var/www/html

if [ ! -f .env ] && [ -f .env.example ]; then
  cp .env.example .env
fi

if [ -n "${APP_KEY:-}" ] && [ -f .env ]; then
  if grep -q '^APP_KEY=$' .env || ! grep -q '^APP_KEY=' .env; then
    sed -i "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" .env 2>/dev/null || echo "APP_KEY=${APP_KEY}" >> .env
  fi
fi

# Named volumes overlay image-owned storage/. Scheduler often writes as root,
# then php-fpm (www-data) cannot append laravel.log and join requests 500.
mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions \
  storage/framework/views storage/app/public bootstrap/cache
if [ "$(id -u)" = "0" ]; then
  chown -R www-data:www-data storage bootstrap/cache || true
  chmod -R ug+rwx storage bootstrap/cache || true
fi

php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true

exec "$@"
