#!/bin/bash
# Production deploy — run from Laravel root on the server (where artisan lives)
# Usage: bash scripts/deploy-production.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "=========================================="
echo " Parrot Academy API — production deploy"
echo "=========================================="
echo "Path: $ROOT"
echo ""

if [ ! -f artisan ]; then
  echo "ERROR: artisan not found. Run this from the Laravel project root."
  exit 1
fi

# --- Optional: pull latest (uncomment if server uses git) ---
# git pull origin main

echo "==> 1/7 Composer (production)"
if [ -f composer.phar ]; then
  php composer.phar install --no-dev --optimize-autoloader --no-interaction
else
  composer install --no-dev --optimize-autoloader --no-interaction
fi

echo "==> 2/7 Storage permissions"
mkdir -p storage/framework/{cache/data,sessions,views,testing} storage/logs storage/app/public bootstrap/cache
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

echo "==> 3/7 Database migrations (CRITICAL — applies all schema changes)"
php artisan migrate --force

echo "==> 4/7 Migration status (last 15)"
php artisan migrate:status | tail -15

echo "==> 5/7 Clear stale caches"
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

echo "==> 6/7 Rebuild production caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> 7/7 Schema + pCloud health"
php artisan tinker --execute="echo json_encode(app(\App\Services\DatabaseSchemaService::class)->status(), JSON_PRETTY_PRINT);"

API_URL="${APP_URL:-https://api.xanderglobalscholars.com}"
FRONTEND_URL="${FRONTEND_URL:-https://xanderglobalacademy.com}"
echo ""
echo "=========================================="
echo " Manual verification (run after deploy):"
echo "=========================================="
echo ""
echo "# Health (schema + DB + pCloud):"
echo "curl -s \"${API_URL}/api/admin/system/health\" | jq ."
echo ""
echo "# Institution links (must return JSON, not HTML 500):"
echo "curl -s \"${API_URL}/api/admin/institution-signup/by-slug/prime-gataway\""
echo "curl -s \"${API_URL}/api/admin/institution-signup/portal/prime-gataway\""
echo ""
echo "# Frontend pages:"
echo "${FRONTEND_URL}/i/prime-gataway"
echo "${FRONTEND_URL}/join/prime-gataway"
echo "${FRONTEND_URL}/login/prime-gataway"
echo ""
echo "# Or trigger migrate remotely (if MIGRATE_TOKEN is set in .env):"
echo "curl -X POST \"${API_URL}/api/admin/system/migrate\" -H \"X-Migrate-Token: YOUR_MIGRATE_TOKEN\""
echo ""
echo "# Quiz tables — should exist after migrate:"
echo "php artisan tinker --execute=\""
echo "  echo Schema::hasTable('quiz_attempts') ? 'quiz_attempts OK' : 'MISSING quiz_attempts';"
echo "  echo PHP_EOL;"
echo "  echo Schema::hasTable('quiz_material_analyses') ? 'quiz_material_analyses OK' : 'MISSING quiz_material_analyses';"
echo "  echo PHP_EOL;"
echo "  echo Schema::hasColumn('quiz_attempts','marking_provider') ? 'marking_provider OK' : 'MISSING marking_provider';"
echo "\""
echo ""
echo "Deploy script finished."
