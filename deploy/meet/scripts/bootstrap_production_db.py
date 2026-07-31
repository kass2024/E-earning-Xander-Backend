#!/usr/bin/env python3
"""Full DB migrate + seed on Xander Meet production via SSH."""
from __future__ import annotations

import sys
import time
from pathlib import Path

import paramiko

DEPLOY = Path(__file__).resolve().parents[2]
cfg = {}
for line in (DEPLOY / "vps.env").read_text().splitlines():
    if "=" in line and not line.strip().startswith("#"):
        k, v = line.split("=", 1)
        cfg[k.strip()] = v.strip()


def run(client: paramiko.SSHClient, cmd: str, timeout: int = 3600) -> int:
    print(f"\n$ {cmd[:500]}")
    _, stdout, _ = client.exec_command(cmd, get_pty=True, timeout=timeout)
    ch = stdout.channel
    while True:
        while ch.recv_ready():
            sys.stdout.buffer.write(ch.recv(4096))
            sys.stdout.buffer.flush()
        if ch.exit_status_ready():
            while ch.recv_ready():
                sys.stdout.buffer.write(ch.recv(4096))
                sys.stdout.buffer.flush()
            break
        time.sleep(0.2)
    code = ch.recv_exit_status()
    print(f"\n[exit {code}]")
    return code


def main() -> int:
    c = paramiko.SSHClient()
    c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    c.connect("66.29.135.120", username="root", password=cfg["VPS_PASSWORD"], timeout=30)

    cmd = """
set -e
MEET=/opt/xander-meet
cd $MEET/E-learning-parrot-backend && git fetch origin && git reset --hard origin/main
cd $MEET/E-learning-parrot-frontend && git fetch origin && git reset --hard origin/main
cd $MEET/E-learning-parrot-backend/deploy/meet
export MEET_HTTP_PORT=8190
docker compose -f docker-compose.prod.yml --env-file .env.production up -d --build backend scheduler
sleep 5
docker exec meet_backend php artisan migrate --force
docker exec meet_backend php artisan db:seed --force
docker exec meet_backend php artisan config:clear
docker exec meet_backend php artisan cache:clear
docker exec meet_backend php artisan mopay:register-callbacks 2>/dev/null || echo MOPAY_SKIP
docker exec meet_backend php artisan daily:webhook:configure --url=https://meet.xandertech.llc/api/webhooks/daily 2>/dev/null || echo DAILY_SKIP
echo "--- USERS ---"
docker exec meet_mysql mysql -umeet -p$(grep ^DB_PASSWORD= .env.production | cut -d= -f2) xander_meet -e "SELECT id,name,email,role,status FROM users ORDER BY role,id;"
echo "--- PLANS ---"
docker exec meet_backend php artisan tinker --execute="echo App\\\\Models\\\\MeetSubscriptionPlan::count().' plans';"
echo "--- SUBSCRIPTIONS ---"
docker exec meet_backend php artisan tinker --execute="echo App\\\\Models\\\\MeetSubscription::where('status','active')->count().' active subs';"
"""
    code = run(c, cmd, timeout=7200)
    c.close()
    return code


if __name__ == "__main__":
    raise SystemExit(main())
