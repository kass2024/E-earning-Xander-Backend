#!/bin/bash
# Run on VPS: pull code, rebuild containers, optionally import DB dump.
# Path: /opt/e-learning-xander/E-learning-parrot-backend/deploy/scripts/vps-deploy.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"           # backend repo root
DEPLOY="$(cd "$(dirname "$0")/.." && pwd)"           # deploy/
BASE="$(cd "$ROOT/.." && pwd)"                      # /opt/e-learning-xander
FRONTEND="$BASE/E-learning-parrot-frontend"
IMPORT_DB="${IMPORT_DB:-0}"

echo "==> Safety: never modify /var/www"
ls /var/www >/dev/null 2>&1 && echo "    /var/www present (unchanged)" || true

if [ ! -d "$FRONTEND/.git" ]; then
  echo "ERROR: Frontend missing at $FRONTEND"
  echo "Run: git clone https://github.com/kass2024/E-earning-Xander-front-end.git $FRONTEND"
  exit 1
fi

echo "==> Pull frontend"
git -C "$FRONTEND" fetch origin
git -C "$FRONTEND" checkout main
git -C "$FRONTEND" pull --ff-only origin main

echo "==> Pull backend (includes deploy/)"
git -C "$ROOT" fetch origin
git -C "$ROOT" checkout main
git -C "$ROOT" pull --ff-only origin main

cd "$DEPLOY"

if [ ! -f .env.production ]; then
  if [ -f env.production.example ]; then
    cp env.production.example .env.production
    echo "Created .env.production from example — edit secrets before production use."
  else
    echo "ERROR: .env.production missing"
    exit 1
  fi
fi

# Ensure APP_KEY
if ! grep -q '^APP_KEY=base64:' .env.production 2>/dev/null; then
  echo "==> Generating APP_KEY"
  KEY=$(docker compose -f docker-compose.prod.yml --env-file .env.production run --rm --no-deps backend php -r "echo 'base64:'.base64_encode(random_bytes(32));" 2>/dev/null || true)
  if [ -n "${KEY:-}" ]; then
    sed -i "s|^APP_KEY=.*|APP_KEY=${KEY}|" .env.production
  fi
fi

echo "==> Build & start containers (127.0.0.1:8090 only)"
docker compose -f docker-compose.prod.yml --env-file .env.production up -d --build

# Always recreate edge nginx after frontend/backend rebuilds so upstream DNS
# never points at a stale container IP (classic 502 Bad Gateway cause).
echo "==> Recreate edge nginx (refresh Docker DNS upstreams)"
docker compose -f docker-compose.prod.yml --env-file .env.production up -d --force-recreate --no-deps nginx

echo "==> Run migrations"
docker compose -f docker-compose.prod.yml --env-file .env.production exec -T backend php artisan migrate --force || true

if [ "$IMPORT_DB" = "1" ]; then
  DUMP=""
  if [ -f "$DEPLOY/db/latest.sql.gz" ]; then
    DUMP="$DEPLOY/db/latest.sql.gz"
  elif [ -f "$DEPLOY/db/latest.sql" ]; then
    DUMP="$DEPLOY/db/latest.sql"
  fi
  if [ -n "$DUMP" ]; then
    echo "==> Importing database from $DUMP"
    # shellcheck disable=SC1091
    set -a
    # shellcheck source=/dev/null
    source <(grep -E '^(DB_DATABASE|DB_USERNAME|DB_PASSWORD|MYSQL_ROOT_PASSWORD)=' .env.production | sed 's/\r$//')
    set +a
    echo "Waiting for MySQL..."
    for i in $(seq 1 30); do
      if docker exec parrot_mysql mysqladmin ping -h localhost -uroot -p"$MYSQL_ROOT_PASSWORD" --silent 2>/dev/null; then
        break
      fi
      sleep 2
    done
    if [[ "$DUMP" == *.gz ]]; then
      gunzip -c "$DUMP" | docker exec -i parrot_mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$DB_DATABASE"
    else
      docker exec -i parrot_mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$DB_DATABASE" < "$DUMP"
    fi
    echo "DB import done."
  else
    echo "IMPORT_DB=1 but no deploy/db/latest.sql(.gz) found — skipping."
  fi
fi

echo "==> Ensure Apache proxy vhost exists (does not touch /var/www)"
if [ -f "$DEPLOY/scripts/setup-apache-proxy.sh" ]; then
  chmod +x "$DEPLOY/scripts/setup-apache-proxy.sh"
  if [ -f /etc/apache2/sites-enabled/xander-academy-elearning.conf ]; then
    echo "    Apache e-learning vhost already enabled."
  else
    PARROT_HTTP_PORT=8090 bash "$DEPLOY/scripts/setup-apache-proxy.sh" || true
  fi
fi

echo "==> Health checks"
curl -sS -o /dev/null -w "frontend:%{http_code}\n" -H "Host: xanderglobalacademy.com" http://127.0.0.1:8090/ || true
curl -sS -o /dev/null -w "api_up:%{http_code}\n" -H "Host: api.xanderglobalacademy.com" http://127.0.0.1:8090/up || true
curl -sS -o /dev/null -w "public_https:%{http_code}\n" https://xanderglobalacademy.com/ || true

if [ -f "$DEPLOY/scripts/e2e_meeting_engagement.php" ]; then
  echo "==> E2E public smoke"
  docker compose -f docker-compose.prod.yml --env-file .env.production exec -T backend \
    php /var/www/html/deploy/scripts/e2e_meeting_engagement.php || \
    php "$DEPLOY/scripts/e2e_meeting_engagement.php" || true
fi

docker compose -f docker-compose.prod.yml --env-file .env.production ps
echo "DONE. Front https://xanderglobalacademy.com | API https://api.xanderglobalacademy.com"
