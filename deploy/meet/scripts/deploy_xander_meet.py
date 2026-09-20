"""Deploy Xander Meet to VPS at /opt/xander-meet (port 8091, meet.xandertech.llc)."""
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent.parent / "scripts"))
import ssh_deploy as d
import paramiko

DEPLOY = Path(__file__).resolve().parent.parent
cfg = d.load_env(d.DEPLOY / "vps.env")
user, host, port = d.parse_host(cfg["VPS_HOST"])
password = cfg["VPS_PASSWORD"]

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(hostname=host, port=port, username=user, password=password, timeout=30)

cmd = r"""
set -e
MEET_ROOT=/opt/xander-meet
mkdir -p "$MEET_ROOT"

if [ ! -d "$MEET_ROOT/E-learning-parrot-backend/.git" ]; then
  git clone https://github.com/kass2024/E-earning-Xander-Backend.git "$MEET_ROOT/E-learning-parrot-backend"
fi
if [ ! -d "$MEET_ROOT/E-learning-parrot-frontend/.git" ]; then
  git clone https://github.com/kass2024/E-earning-Xander-front-end.git "$MEET_ROOT/E-learning-parrot-frontend"
fi

cd "$MEET_ROOT/E-learning-parrot-backend"
git fetch origin && git reset --hard origin/main

cd "$MEET_ROOT/E-learning-parrot-frontend"
git fetch origin && git reset --hard origin/main

cd "$MEET_ROOT/E-learning-parrot-backend/deploy/meet"
if [ ! -f .env.production ]; then
  cp ../env.production.example .env.production 2>/dev/null || cp ../../.env.example .env.production
  sed -i 's|VITE_APP_NAME=.*|VITE_APP_NAME=Xander Meet|' .env.production 2>/dev/null || true
  sed -i 's|APP_NAME=.*|APP_NAME="Xander Meet"|' .env.production 2>/dev/null || true
  sed -i 's|FRONTEND_URL=.*|FRONTEND_URL=https://meet.xandertech.llc|' .env.production 2>/dev/null || true
  sed -i 's|VITE_API_URL=.*|VITE_API_URL=https://api.meet.xandertech.llc|' .env.production 2>/dev/null || true
fi

export MEET_HTTP_PORT=8190
docker rm -f meet_nginx meet_frontend meet_backend meet_scheduler meet_mysql 2>/dev/null || true
docker compose -f docker-compose.prod.yml --env-file .env.production down 2>/dev/null || true
docker compose -f docker-compose.prod.yml --env-file .env.production up -d --build

docker exec -u root meet_backend sh -c 'mkdir -p /var/www/html/storage/logs /var/www/html/bootstrap/cache && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache && chmod -R ug+rwx /var/www/html/storage /var/www/html/bootstrap/cache' || true

docker exec meet_backend php artisan migrate --force 2>/dev/null || true
docker exec meet_backend php artisan db:seed --class=MeetSubscriptionPlanSeeder --force 2>/dev/null || true

MEET_HTTP_PORT=8190 bash scripts/setup-apache-meet.sh 2>/dev/null || true

echo "Xander Meet deployed at meet.xandertech.llc (port 8190)"
"""
raise SystemExit(d.run(client, cmd, timeout=7200))
