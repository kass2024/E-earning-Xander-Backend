#!/usr/bin/env python3
import sys
from pathlib import Path
import paramiko

DEPLOY = Path(__file__).resolve().parents[2]  # deploy/
cfg = {}
for line in (DEPLOY / "vps.env").read_text().splitlines():
    if "=" in line and not line.strip().startswith("#"):
        k, v = line.split("=", 1)
        cfg[k.strip()] = v.strip()

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("66.29.135.120", username="root", password=cfg["VPS_PASSWORD"], timeout=30)

checks = [
    'docker ps --filter name=meet_ --format "{{.Names}} | {{.Status}} | {{.Ports}}"',
    'curl -s -o /dev/null -w "docker_frontend:%{http_code}\\n" http://127.0.0.1:8190/',
    'curl -s -o /dev/null -w "apache_frontend:%{http_code}\\n" -H "Host: meet.xandertech.llc" http://127.0.0.1/',
    'curl -s http://127.0.0.1:8190/api/admin/meet/plans | head -c 400',
    'curl -s http://127.0.0.1:8190/api/admin/system/health | head -c 200',
    'test -f /opt/xander-meet/E-learning-parrot-backend/deploy/meet/.env.production && echo ENV_OK || echo ENV_MISSING',
]
for cmd in checks:
    print(f"\n$ {cmd}")
    _, o, e = c.exec_command(cmd)
    out = o.read().decode() + e.read().decode()
    print(out.strip())
c.close()
