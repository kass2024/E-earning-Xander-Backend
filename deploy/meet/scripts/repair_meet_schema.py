#!/usr/bin/env python3
"""Diagnose and repair Xander Meet production DB schema + re-seed."""
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
    print(f"\n$ {cmd[:600]}")
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
cd $MEET/E-learning-parrot-backend/deploy/meet
export MEET_HTTP_PORT=8190
docker compose -f docker-compose.prod.yml --env-file .env.production up -d backend mysql
sleep 8
echo "--- describe users ---"
docker exec meet_backend php artisan tinker --execute="try { \\\$c=Illuminate\\\\Support\\\\Facades\\\\Schema::getColumnListing('users'); echo implode(',', \\\$c); } catch (Throwable \\\$e) { echo 'ERR:'.\\\$e->getMessage(); }" || true
echo "--- migrate ---"
docker exec meet_backend php artisan migrate --force
echo "--- schema health ---"
docker exec meet_backend php artisan tinker --execute="\\\$s=app(App\\\\Services\\\\DatabaseSchemaService::class); print_r(\\\$s->verifySchema()['users'] ?? []);" || true
echo "--- seed ---"
docker exec meet_backend php artisan db:seed --force
docker exec meet_backend php artisan cache:clear
docker exec meet_backend php artisan config:clear
echo "--- login probe ---"
docker exec meet_backend php artisan tinker --execute="\\\$u=App\\\\Models\\\\User::where('email','info@xanderglobalscholars.com')->first(); echo \\\$u ? \\\$u->id.' '.\\\$u->role : 'NO_USER';" || true
curl -sf http://127.0.0.1:8190/api/admin/system/health | head -c 400 || echo HEALTH_FAIL
"""
    code = run(c, cmd, timeout=7200)
    c.close()
    return code


if __name__ == "__main__":
    raise SystemExit(main())
