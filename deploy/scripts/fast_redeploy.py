#!/usr/bin/env python3
"""Fast deploy: pull code, hot-copy backend, upload prebuilt frontend dist — no full Docker rebuild."""
from __future__ import annotations

import subprocess
import sys
import tarfile
import time
from io import BytesIO
from pathlib import Path

import paramiko

SCRIPTS = Path(__file__).resolve().parent
DEPLOY = SCRIPTS.parent
BACKEND = DEPLOY.parent
FRONTEND = BACKEND.parent / "E-learning-parrot-frontend"
REMOTE_BASE = "/opt/e-learning-xander"


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
    user, host = "root", vps_host
    port = 22
    if "@" in vps_host:
        user, host = vps_host.split("@", 1)
    if ":" in host and host.count(":") == 1:
        host, port_s = host.rsplit(":", 1)
        if port_s.isdigit():
            port = int(port_s)
    return user, host, port


def run_remote(client: paramiko.SSHClient, cmd: str, timeout: int = 600) -> int:
    print(f"\n$ {cmd}")
    _, stdout, _ = client.exec_command(cmd, get_pty=True, timeout=timeout)
    ch = stdout.channel
    while True:
        while ch.recv_ready():
            chunk = ch.recv(4096)
            sys.stdout.buffer.write(chunk)
            sys.stdout.buffer.flush()
        if ch.exit_status_ready() and not ch.recv_ready():
            break
        time.sleep(0.05)
    code = ch.recv_exit_status()
    print(f"\n[exit {code}]")
    return code


def detect_containers(client: paramiko.SSHClient) -> dict[str, str]:
    code = run_remote(
        client,
        "docker ps -a --format '{{.Names}}' | sort",
        timeout=180,
    )
    if code != 0:
        return {"backend": "", "scheduler": "", "frontend": "", "nginx": ""}
    # Names printed by run_remote; re-fetch with a fresh short command.
    _, stdout, _ = client.exec_command(
        "docker ps -a --format '{{.Names}}'",
        timeout=180,
    )
    stdout.channel.settimeout(180.0)
    names = {n.strip() for n in stdout.read().decode().splitlines() if n.strip()}

    def pick(prefix: str, fallback_prefix: str) -> str:
        for p in (prefix, fallback_prefix):
            for n in names:
                if n.startswith(p):
                    return n
        return ""

    return {
        "backend": pick("xander_backend", "parrot_backend"),
        "scheduler": pick("xander_scheduler", "parrot_scheduler"),
        "frontend": pick("xander_frontend", "parrot_frontend"),
        "nginx": pick("xander_nginx", "parrot_nginx"),
    }


def build_frontend_local() -> None:
    dist = FRONTEND / "dist"
    index = dist / "index.html"
    if index.is_file() and index.stat().st_mtime > time.time() - 3600:
        print("==> Reusing recent frontend dist/ (built within last hour)")
        return
    print("==> Building frontend locally (npm run build)")
    subprocess.run(
        ["npm", "run", "build"],
        cwd=FRONTEND,
        check=True,
        shell=True,
    )
    if not dist.is_dir():
        raise SystemExit("Frontend dist/ missing after build")


def upload_dist(client: paramiko.SSHClient, frontend: str) -> None:
    dist = FRONTEND / "dist"
    buf = BytesIO()
    with tarfile.open(fileobj=buf, mode="w:gz") as tar:
        tar.add(dist, arcname="dist")
    payload = buf.getvalue()
    print(f"==> Uploading frontend dist ({len(payload) // 1024} KB)")

    remote_tar = "/tmp/xander-frontend-fast.tar.gz"
    sftp = client.open_sftp()
    with sftp.file(remote_tar, "wb") as rf:
        rf.write(payload)
    sftp.close()

    code = run_remote(
        client,
        f"""
set -e
rm -rf /tmp/xander-frontend-fast
mkdir -p /tmp/xander-frontend-fast
tar -xzf {remote_tar} -C /tmp/xander-frontend-fast
docker cp /tmp/xander-frontend-fast/dist/. {frontend}:/usr/share/nginx/html/
docker exec {frontend} nginx -s reload 2>/dev/null || docker restart {frontend}
echo FRONTEND_OK
""",
        timeout=120,
    )
    if code != 0:
        raise SystemExit("Frontend upload failed")


def main() -> int:
    cfg = load_env(DEPLOY / "vps.env")
    user, host, port = parse_host(cfg["VPS_HOST"])
    password = cfg["VPS_PASSWORD"]

    build_frontend_local()

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"==> Connecting to {user}@{host}:{port}")
    last_err: Exception | None = None
    for attempt in range(8):
        try:
            client.connect(
                hostname=host,
                port=port,
                username=user,
                password=password,
                timeout=90,
                banner_timeout=90,
                auth_timeout=90,
            )
            last_err = None
            break
        except Exception as exc:
            last_err = exc
            print(f"SSH attempt {attempt + 1} failed: {exc}")
            time.sleep(15)
    if last_err is not None:
        raise SystemExit(f"Could not SSH to VPS: {last_err}")
    client.get_transport().set_keepalive(15)

    print("==> Unblocking Docker (kill stuck builds from prior full deploy)")
    run_remote(
        client,
        "pkill -9 -f 'docker compose.*build' 2>/dev/null || true; "
        "pkill -9 -f 'npm run build' 2>/dev/null || true; "
        "pkill -9 -f 'vite build' 2>/dev/null || true; "
        "sleep 5; "
        "systemctl restart docker 2>/dev/null || service docker restart 2>/dev/null || true; "
        "sleep 8; "
        "echo docker_unblocked",
        timeout=180,
    )

    containers = detect_containers(client)
    print("==> Containers:", containers)

    deploy_dir = f"{REMOTE_BASE}/E-learning-parrot-backend/deploy"

    if not containers["backend"]:
        print("==> No backend container — starting stack without rebuild (fast)")
        code = run_remote(
            client,
            f"""
set -e
cd {deploy_dir}
docker compose -f docker-compose.prod.yml --env-file .env.production up -d --no-build 2>/dev/null \
  || docker compose -f docker-compose.prod.yml --env-file .env.production up -d mysql backend frontend nginx
sleep 5
docker ps --format '{{{{.Names}}}} {{{{.Status}}}}'
""",
            timeout=180,
        )
        if code != 0:
            client.close()
            return code
        containers = detect_containers(client)
        print("==> Containers after up:", containers)

    backend = containers["backend"]
    scheduler = containers["scheduler"]
    frontend = containers["frontend"]
    if not backend or not frontend:
        print("ERROR: backend/frontend containers still missing", file=sys.stderr)
        client.close()
        return 1

    # Stop any stuck full rebuild started by a prior deploy.
    run_remote(
        client,
        "pkill -f 'docker compose.*build' 2>/dev/null || true; "
        "pkill -f 'npm run build' 2>/dev/null || true; "
        "echo cleared_stuck_builds",
        timeout=30,
    )

    backend_repo = f"{REMOTE_BASE}/E-learning-parrot-backend"
    frontend_repo = f"{REMOTE_BASE}/E-learning-parrot-frontend"

    code = run_remote(
        client,
        f"""
set -e
cd {backend_repo} && git fetch origin && git reset --hard origin/main
cd {frontend_repo} && git fetch origin && git reset --hard origin/main
echo GIT_OK
""",
        timeout=180,
    )
    if code != 0:
        client.close()
        return code

    scheduler_step = (
        f'docker cp "$SRC/app/." {scheduler}:/var/www/html/app/ && '
        f'docker cp "$SRC/config/." {scheduler}:/var/www/html/config/ && '
        f'docker cp "$SRC/database/." {scheduler}:/var/www/html/database/ && '
        f"docker restart {scheduler}"
        if scheduler
        else "echo no_scheduler"
    )

    # Hot-copy PHP code into running backend — avoids 20+ min Docker rebuild.
    code = run_remote(
        client,
        f"""
set -e
B={backend}
SRC={backend_repo}
docker cp "$SRC/app/." "$B:/var/www/html/app/"
docker cp "$SRC/config/." "$B:/var/www/html/config/"
docker cp "$SRC/database/." "$B:/var/www/html/database/"
docker cp "$SRC/routes/." "$B:/var/www/html/routes/"
docker cp "$SRC/resources/." "$B:/var/www/html/resources/"
docker exec $B php artisan config:clear
docker exec $B php artisan route:clear
docker exec $B php artisan migrate --force
docker exec $B php artisan db:seed --class=DatabaseSeeder --force
docker restart $B
{scheduler_step}
echo BACKEND_OK
""",
        timeout=300,
    )
    if code != 0:
        client.close()
        return code

    upload_dist(client, frontend)

    run_remote(
        client,
        "curl -sS -o /dev/null -w 'frontend:%{http_code}\\n' -H 'Host: xanderglobalacademy.com' http://127.0.0.1:8090/ || true",
        timeout=30,
    )
    run_remote(client, "docker ps --filter name=xander_ --format '{{.Names}} {{.Status}}'", timeout=30)

    client.close()
    print("\nFAST DEPLOY DONE.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
