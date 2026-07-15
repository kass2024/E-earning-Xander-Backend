#!/usr/bin/env python3
"""Password SSH deploy helper for Xander Academy VPS. Reads deploy/vps.env."""
from __future__ import annotations

import os
import sys
import time
from pathlib import Path

import paramiko

DEPLOY = Path(__file__).resolve().parent.parent
BACKEND = DEPLOY.parent


def load_env(path: Path) -> dict[str, str]:
    out: dict[str, str] = {}
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        out[k.strip()] = v.strip()
    return out


def parse_host(vps_host: str) -> tuple[str, str, int]:
    # root@66.29.135.120 or root@host:2222
    user = "root"
    host = vps_host
    port = 22
    if "@" in vps_host:
        user, host = vps_host.split("@", 1)
    if ":" in host and host.count(":") == 1:
        host, port_s = host.rsplit(":", 1)
        if port_s.isdigit():
            port = int(port_s)
    return user, host, port


def run(client: paramiko.SSHClient, cmd: str, timeout: int = 3600) -> int:
    print(f"\n$ {cmd}")
    stdin, stdout, stderr = client.exec_command(cmd, get_pty=True, timeout=timeout)
    channel = stdout.channel
    while True:
        while channel.recv_ready():
            chunk = channel.recv(4096).decode("utf-8", errors="replace")
            sys.stdout.buffer.write(chunk.encode("utf-8", errors="replace"))
            sys.stdout.buffer.flush()
        while channel.recv_stderr_ready():
            chunk = channel.recv_stderr(4096).decode("utf-8", errors="replace")
            sys.stdout.buffer.write(chunk.encode("utf-8", errors="replace"))
            sys.stdout.buffer.flush()
        if channel.exit_status_ready() and not channel.recv_ready() and not channel.recv_stderr_ready():
            break
        time.sleep(0.1)
    code = channel.recv_exit_status()
    if code != 0:
        print(f"\n[exit {code}]")
    return code


def upload(client: paramiko.SSHClient, local: Path, remote: str) -> None:
    print(f"\n==> upload {local} -> {remote}")
    sftp = client.open_sftp()
    try:
        # ensure remote dir
        remote_dir = os.path.dirname(remote)
        run(client, f"mkdir -p '{remote_dir}'")
        sftp.put(str(local), remote)
    finally:
        sftp.close()


def main() -> int:
    cfg = load_env(DEPLOY / "vps.env")
    user, host, port = parse_host(cfg.get("VPS_HOST", ""))
    password = cfg.get("VPS_PASSWORD", "")
    vps_path = cfg.get("VPS_PATH", "/opt/e-learning-xander")
    import_db = cfg.get("IMPORT_DB", "1")

    if not host or not password:
        print("VPS_HOST and VPS_PASSWORD required in deploy/vps.env", file=sys.stderr)
        return 1

    env_file = DEPLOY / ".env.production"
    dump = DEPLOY / "db" / "latest.sql.gz"
    if not env_file.exists():
        print("Missing .env.production — run prepare-env.ps1 first", file=sys.stderr)
        return 1

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"==> SSH {user}@{host}:{port}")
    client.connect(hostname=host, port=port, username=user, password=password, timeout=30)

    # Bootstrap dirs + packages (never touch /var/www)
    boot = f"""
set -e
echo '==> Existing /var/www (left unchanged):'
ls /var/www || true
mkdir -p '{vps_path}'
cd '{vps_path}'
command -v git >/dev/null || (apt-get update -y && apt-get install -y git curl)
if ! command -v docker >/dev/null 2>&1; then
  echo '==> Installing Docker'
  curl -fsSL https://get.docker.com | sh
fi
systemctl enable --now docker || true
if [ ! -d E-learning-parrot-backend/.git ]; then
  git clone https://github.com/kass2024/E-earning-Xander-Backend.git E-learning-parrot-backend
fi
if [ ! -d E-learning-parrot-frontend/.git ]; then
  git clone https://github.com/kass2024/E-earning-Xander-front-end.git E-learning-parrot-frontend
fi
mkdir -p E-learning-parrot-backend/deploy/db
chmod +x E-learning-parrot-backend/deploy/scripts/*.sh || true
echo OK_BOOT
"""
    if run(client, boot) != 0:
        client.close()
        return 1

    # Upload secrets + ensure Unix line endings on remote shell scripts
    remote_deploy = f"{vps_path}/E-learning-parrot-backend/deploy"
    upload(client, env_file, f"{remote_deploy}/.env.production")
    if import_db == "1" and dump.exists():
        upload(client, dump, f"{remote_deploy}/db/latest.sql.gz")
    elif import_db == "1":
        print("WARN: IMPORT_DB=1 but latest.sql.gz missing")

    for script in ("vps-deploy.sh", "setup-apache-proxy.sh", "export-db.sh"):
        local_script = DEPLOY / "scripts" / script
        if local_script.exists():
            upload(client, local_script, f"{remote_deploy}/scripts/{script}")

    # Pull latest app code (keep uploaded env/db/scripts)
    pull = f"""
set -e
cd '{vps_path}/E-learning-parrot-backend'
git fetch origin
git checkout main
git pull --ff-only origin main || true
chmod +x deploy/scripts/*.sh
# Strip CRLF if any Windows upload contaminated scripts
sed -i 's/\\r$//' deploy/scripts/*.sh || true
"""
    if run(client, pull) != 0:
        client.close()
        return 1

    # Re-upload after pull (gitignored + scripts we normalize locally)
    upload(client, env_file, f"{remote_deploy}/.env.production")
    if import_db == "1" and dump.exists():
        upload(client, dump, f"{remote_deploy}/db/latest.sql.gz")
    for script in ("vps-deploy.sh", "setup-apache-proxy.sh", "export-db.sh"):
        local_script = DEPLOY / "scripts" / script
        if local_script.exists():
            upload(client, local_script, f"{remote_deploy}/scripts/{script}")
    run(client, f"sed -i 's/\\r$//' '{remote_deploy}/scripts/'*.sh && chmod +x '{remote_deploy}/scripts/'*.sh")

    deploy_cmd = f"IMPORT_DB={import_db} bash '{remote_deploy}/scripts/vps-deploy.sh'"
    code = run(client, deploy_cmd, timeout=7200)
    client.close()
    return code


if __name__ == "__main__":
    raise SystemExit(main())
