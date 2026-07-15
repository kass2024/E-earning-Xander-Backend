#!/bin/bash
# Export local MySQL DB to deploy/db/latest.sql.gz (Linux / Git Bash)
set -euo pipefail

DEPLOY="$(cd "$(dirname "$0")/.." && pwd)"
BACKEND="$(cd "$DEPLOY/.." && pwd)"
OUT_DIR="$DEPLOY/db"
mkdir -p "$OUT_DIR"

ENV_FILE="$BACKEND/.env"
if [ ! -f "$ENV_FILE" ]; then
  echo "ERROR: $ENV_FILE not found"
  exit 1
fi

DB_DATABASE=$(grep -E '^DB_DATABASE=' "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d '\r' | tr -d ' "')
DB_USERNAME=$(grep -E '^DB_USERNAME=' "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d '\r' | tr -d ' "')
DB_PASSWORD=$(grep -E '^DB_PASSWORD=' "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d '\r' | tr -d ' "')
DB_HOST=$(grep -E '^DB_HOST=' "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d '\r' | tr -d ' "')
DB_PORT=$(grep -E '^DB_PORT=' "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d '\r' | tr -d ' "')

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_USERNAME="${DB_USERNAME:-root}"

STAMP=$(date +%Y%m%d-%H%M%S)
RAW="$OUT_DIR/learning-xander-$STAMP.sql"
GZ="$OUT_DIR/latest.sql.gz"

echo "==> Dumping $DB_DATABASE from $DB_HOST:$DB_PORT"
if [ -n "${DB_PASSWORD:-}" ]; then
  mysqldump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" -p"$DB_PASSWORD" \
    --single-transaction --routines --triggers --hex-blob "$DB_DATABASE" > "$RAW"
else
  mysqldump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" \
    --single-transaction --routines --triggers --hex-blob "$DB_DATABASE" > "$RAW"
fi

gzip -c "$RAW" > "$GZ"
rm -f "$RAW"
echo "Wrote $GZ"
ls -lh "$GZ"
