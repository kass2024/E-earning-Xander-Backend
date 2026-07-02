#!/bin/bash
# Run on cPanel terminal from the Laravel project root (where artisan lives)
set -e

echo "==> Parrot API cPanel setup"
cd "$(dirname "$0")/.."

if [ ! -f artisan ]; then
  echo "ERROR: Run this from the Laravel root (artisan not found)."
  exit 1
fi

# .htaccess (required — LiteSpeed returns 404 without it)
if [ ! -f .htaccess ]; then
  cp .htaccess.example .htaccess
  echo "Created .htaccess from .htaccess.example"
fi

# storage skeleton
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/framework/testing
mkdir -p storage/logs
mkdir -p storage/app/public
mkdir -p bootstrap/cache

chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# Composer
if [ ! -d vendor ]; then
  if [ -f composer.phar ]; then
    php composer.phar install --no-dev --optimize-autoloader
  else
    echo "WARN: vendor/ missing — run: php composer.phar install --no-dev --optimize-autoloader"
  fi
fi

# .env
if [ ! -f .env ]; then
  cp .env.example .env
  php artisan key:generate
  echo "Created .env — edit DB and mail settings before continuing."
fi

php artisan migrate --force || true
php artisan storage:link || true

php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache

echo ""
echo "==> Test:"
echo "curl -s \"\${APP_URL:-https://api.parrotglobalstudyacademy.ca}/api/admin/system/health\""
echo "curl -s \"\${APP_URL:-https://api.parrotglobalstudyacademy.ca}/up\""
