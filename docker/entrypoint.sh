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

php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true

exec "$@"
