#!/usr/bin/env python3
import sys, time
from pathlib import Path
import paramiko

DEPLOY = Path(__file__).resolve().parent.parent
cfg = {}
for raw in (DEPLOY / "vps.env").read_text().splitlines():
    if raw.strip() and not raw.startswith("#") and "=" in raw:
        k,v = raw.split("=",1); cfg[k.strip()]=v.strip()

c = paramiko.SSHClient(); c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect(cfg["VPS_HOST"].split("@")[1], username=cfg["VPS_HOST"].split("@")[0], password=cfg["VPS_PASSWORD"], timeout=30)

cmd = r"""
set -e
command -v certbot >/dev/null || apt-get install -y certbot python3-certbot-apache
# Only succeed if DNS already points here; otherwise print and continue
if certbot --apache -d xanderglobalacademy.com -d www.xanderglobalacademy.com -d api.xanderglobalacademy.com --non-interactive --agree-tos --register-unsafely-without-email --redirect; then
  echo SSL_OK
else
  echo SSL_SKIPPED_CHECK_DNS
  certbot certificates || true
fi
echo '=== final status ==='
docker ps --filter name=parrot_ --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'
ls /var/www
curl -sS -o /dev/null -w 'http_front:%{http_code}\n' -H 'Host: xanderglobalacademy.com' http://127.0.0.1/
curl -sS -o /dev/null -w 'http_api:%{http_code}\n' -H 'Host: api.xanderglobalacademy.com' http://127.0.0.1/up
"""
_, out, _ = c.exec_command(cmd, get_pty=True, timeout=300)
ch = out.channel
while True:
    while ch.recv_ready():
        sys.stdout.buffer.write(ch.recv(4096)); sys.stdout.buffer.flush()
    if ch.exit_status_ready() and not ch.recv_ready(): break
    time.sleep(0.1)
print("\nEXIT", ch.recv_exit_status())
c.close()
