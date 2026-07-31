#!/usr/bin/env python3
"""SFTP meet .env.production to VPS via SSH, then rebuild and verify Xander Meet."""
from __future__ import annotations

import secrets
import sys
import time
from pathlib import Path

import paramiko

BACKEND = Path(__file__).resolve().parents[3]
DEPLOY = BACKEND / "deploy"
MEET_DEPLOY = DEPLOY / "meet"
LOCAL_PROD = DEPLOY / ".env.production"
LOCAL_ENV = BACKEND / ".env"
REMOTE_ENV = "/opt/xander-meet/E-learning-parrot-backend/deploy/meet/.env.production"
MEET_ROOT = "/opt/xander-meet"

COPY_KEYS = [
    "APP_KEY",
    "ZOOM_ACCOUNT_ID", "ZOOM_CLIENT_ID", "ZOOM_CLIENT_SECRET", "ZOOM_HOST_USER_ID",
    "ZOOM_EMBED_CLIENT_ID", "ZOOM_EMBED_CLIENT_SECRET",
    "STRIPE_SECRET_KEY", "STRIPE_PUBLIC_KEY",
    "MOPAY_PROJECT_SLUG", "MOPAY_MESSAGE_PREFIX", "MOPAY_ACCOUNT_ID", "MOPAY_AUTH_KEY",
    "MOPAY_BEARER_TOKEN", "MOPAY_TOKEN_URL", "MOPAY_CATEGORY", "MOPAY_SERVER_BASE_URL",
    "MOPAY_CALLBACK_SIGNING_KEY", "MOPAY_DEFAULT_COUNTRY_CODE", "MOPAY_DEFAULT_MNO",
    "MOPAY_DEFAULT_CURRENCY", "MOPAY_RECEIVER_ACCOUNT_NO",
    "MAIL_USERNAME", "MAIL_PASSWORD", "MAIL_FROM_ADDRESS", "MAIL_MAILER", "MAIL_PORT",
    "MAIL_SCHEME", "MAIL_ENCRYPTION", "MAIL_EHLO_DOMAIN", "MAIL_VERIFY_PEER", "MAIL_TIMEOUT", "MAIL_HOST",
    "DAILY_INTEGRATION_ENABLED", "DAILY_API_KEY", "DAILY_DOMAIN", "DAILY_API_BASE_URL",
    "DAILY_WEBHOOK_HMAC", "DAILY_WEBHOOK_UUID", "DAILY_WEBHOOK_RETRY_TYPE",
    "DAILY_DEFAULT_LANGUAGE", "DAILY_ROOM_GRACE_MINUTES", "DAILY_TOKEN_GRACE_MINUTES",
    "DAILY_RECORDING_ENABLED", "MAIN_PLATFORM_MEETING_PROVIDER",
    "SEED_PLATFORM_PASSWORD", "PLATFORM_ADMIN_EMAIL",
]

MEET_FORCE = {
    "MEET_HTTP_PORT": "8190",
    "APP_NAME": "Xander Meet",
    "APP_ENV": "production",
    "APP_DEBUG": "false",
    "APP_URL": "https://api.meet.xandertech.llc",
    "FRONTEND_URL": "https://meet.xandertech.llc",
    "VITE_API_URL": "https://meet.xandertech.llc/api/admin",
    "VITE_APP_NAME": "Xander Meet",
    "VITE_APP_VERSION": "1.0.0",
    "VITE_APP_BUILD_ID": "meet-prod",
    "DB_HOST": "mysql",
    "DB_PORT": "3306",
    "DB_CONNECTION": "mysql",
    "DB_DATABASE": "xander_meet",
    "DB_USERNAME": "meet",
    "AUTO_MIGRATE": "true",
    "AUTO_SEED_DEMO": "false",
    "SESSION_DRIVER": "database",
    "SESSION_LIFETIME": "120",
    "CACHE_STORE": "database",
    "QUEUE_CONNECTION": "sync",
    "LOG_CHANNEL": "stack",
    "LOG_LEVEL": "info",
    "DAILY_WEBHOOK_BASE_URL": "https://api.meet.xandertech.llc",
    "DAILY_INTEGRATION_ENABLED": "true",
    "MAIN_PLATFORM_MEETING_PROVIDER": "daily",
    "MOPAY_PROJECT_SLUG": "xander",
    "MOPAY_MESSAGE_PREFIX": "XANDER",
    "MOPAY_CALLBACK_URL": "https://api.meet.xandertech.llc/api/admin/meet/subscription/mopay/webhook",
    "MOPAY_PAYMENT_TITLE": "Xander_meet_subscription",
    "MOPAY_PAYMENT_DETAILS": "Xander Meet monthly subscription",
    "MOPAY_DEFAULT_CURRENCY": "RWF",
    "MOPAY_DEFAULT_COUNTRY_CODE": "rw",
    "MOPAY_DEFAULT_MNO": "mtn",
    "MOPAY_CATEGORY": "BIZAO",
}

PRESERVE_IF_REMOTE = {"MYSQL_ROOT_PASSWORD", "DB_PASSWORD", "DB_USERNAME", "DB_DATABASE"}


def parse_env(text: str) -> dict[str, str]:
    out: dict[str, str] = {}
    for raw in text.splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        out[k.strip()] = v.strip().strip('"')
    return out


def render_env(data: dict[str, str]) -> str:
    order = [
        "MEET_HTTP_PORT", "VITE_API_URL", "VITE_APP_NAME", "VITE_APP_VERSION", "VITE_APP_BUILD_ID",
        "MYSQL_ROOT_PASSWORD", "DB_CONNECTION", "DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD",
        "APP_NAME", "APP_ENV", "APP_KEY", "APP_DEBUG", "APP_URL", "FRONTEND_URL",
        "AUTO_MIGRATE", "AUTO_SEED_DEMO",
        "SESSION_DRIVER", "SESSION_LIFETIME", "CACHE_STORE", "QUEUE_CONNECTION",
        "LOG_CHANNEL", "LOG_LEVEL",
        "ZOOM_ACCOUNT_ID", "ZOOM_CLIENT_ID", "ZOOM_CLIENT_SECRET", "ZOOM_HOST_USER_ID",
        "ZOOM_EMBED_CLIENT_ID", "ZOOM_EMBED_CLIENT_SECRET",
        "STRIPE_SECRET_KEY", "STRIPE_PUBLIC_KEY",
        "DAILY_INTEGRATION_ENABLED", "DAILY_API_KEY", "DAILY_DOMAIN", "DAILY_API_BASE_URL",
        "DAILY_WEBHOOK_BASE_URL", "DAILY_RECORDING_ENABLED", "MAIN_PLATFORM_MEETING_PROVIDER",
        "MOPAY_PROJECT_SLUG", "MOPAY_MESSAGE_PREFIX", "MOPAY_ACCOUNT_ID", "MOPAY_AUTH_KEY",
        "MOPAY_SERVER_BASE_URL", "MOPAY_CALLBACK_SIGNING_KEY", "MOPAY_CALLBACK_URL",
        "MOPAY_DEFAULT_CURRENCY", "MOPAY_DEFAULT_COUNTRY_CODE", "MOPAY_DEFAULT_MNO",
        "MOPAY_PAYMENT_TITLE", "MOPAY_PAYMENT_DETAILS", "MOPAY_CATEGORY",
        "MAIL_MAILER", "MAIL_HOST", "MAIL_PORT", "MAIL_SCHEME", "MAIL_USERNAME", "MAIL_PASSWORD",
        "MAIL_FROM_ADDRESS", "MAIL_FROM_NAME", "MAIL_ENCRYPTION", "MAIL_EHLO_DOMAIN",
        "SEED_PLATFORM_PASSWORD", "PLATFORM_ADMIN_EMAIL",
    ]
    lines = ["# Xander Meet production — uploaded via SSH SFTP\n"]
    seen: set[str] = set()
    for key in order:
        if key in data:
            val = data[key]
            if " " in val or "$" in val:
                lines.append(f'{key}="{val}"')
            else:
                lines.append(f"{key}={val}")
            seen.add(key)
    for key in sorted(data.keys()):
        if key not in seen:
            val = data[key]
            if " " in val or "$" in val:
                lines.append(f'{key}="{val}"')
            else:
                lines.append(f"{key}={val}")
    return "\n".join(lines) + "\n"


def load_vps_cfg() -> dict[str, str]:
    vps_env = DEPLOY / "vps.env"
    out: dict[str, str] = {}
    for raw in vps_env.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        out[k.strip()] = v.strip()
    return out


def run(client: paramiko.SSHClient, cmd: str, timeout: int = 7200) -> int:
    print(f"\n$ {cmd[:2000]}")
    _, stdout, stderr = client.exec_command(cmd, get_pty=True, timeout=timeout)
    channel = stdout.channel
    while True:
        while channel.recv_ready():
            sys.stdout.buffer.write(channel.recv(4096))
            sys.stdout.buffer.flush()
        while channel.recv_stderr_ready():
            sys.stdout.buffer.write(channel.recv_stderr(4096))
            sys.stdout.buffer.flush()
        if channel.exit_status_ready():
            while channel.recv_ready():
                sys.stdout.buffer.write(channel.recv(4096))
                sys.stdout.buffer.flush()
            break
        time.sleep(0.2)
    code = channel.recv_exit_status()
    print(f"\n[exit {code}]")
    return code


def main() -> int:
    cfg = load_vps_cfg()
    host = cfg["VPS_HOST"].split("@")[-1]
    user = cfg["VPS_HOST"].split("@")[0] if "@" in cfg["VPS_HOST"] else "root"
    password = cfg["VPS_PASSWORD"]

    base = parse_env(LOCAL_PROD.read_text(encoding="utf-8"))
    local = parse_env(LOCAL_ENV.read_text(encoding="utf-8"))
    for key in COPY_KEYS:
        if key in local and local[key]:
            base[key] = local[key]
        elif key in base and base[key]:
            pass
    base.update(MEET_FORCE)

    if not base.get("DB_PASSWORD"):
        base["DB_PASSWORD"] = secrets.token_urlsafe(24)
    if not base.get("MYSQL_ROOT_PASSWORD"):
        base["MYSQL_ROOT_PASSWORD"] = secrets.token_urlsafe(24)
    base["MAIL_FROM_NAME"] = "Xander Meet"

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(hostname=host, port=22, username=user, password=password, timeout=30)

    # Preserve DB creds if meet stack already initialized
    try:
        sftp = client.open_sftp()
        try:
            with sftp.file(REMOTE_ENV, "r") as rf:
                remote = parse_env(rf.read().decode("utf-8"))
            for key in PRESERVE_IF_REMOTE:
                if remote.get(key):
                    base[key] = remote[key]
            print(f"Preserved remote DB credentials from existing {REMOTE_ENV}")
        except FileNotFoundError:
            print("No remote .env.production yet — using generated DB passwords")
        sftp.close()
    except Exception as e:
        print(f"Remote env read skipped: {e}")

    env_text = render_env(base)
    local_out = MEET_DEPLOY / ".env.production"
    local_out.write_text(env_text, encoding="utf-8")
    print(f"Wrote local {local_out}")

    sftp = client.open_sftp()
    run(client, f"mkdir -p {MEET_ROOT}/E-learning-parrot-backend/deploy/meet")
    with sftp.file(REMOTE_ENV, "w") as rf:
        rf.write(env_text)
    sftp.chmod(REMOTE_ENV, 0o600)
    sftp.close()
    print(f"SFTP uploaded -> {REMOTE_ENV}")

    deploy_cmd = f"""
set -e
MEET_ROOT={MEET_ROOT}
cd "$MEET_ROOT/E-learning-parrot-backend" && git fetch origin && git reset --hard origin/main
cd "$MEET_ROOT/E-learning-parrot-frontend" && git fetch origin && git reset --hard origin/main
cd "$MEET_ROOT/E-learning-parrot-backend/deploy/meet"
export MEET_HTTP_PORT=8190
docker rm -f meet_nginx 2>/dev/null || true
docker compose -f docker-compose.prod.yml --env-file .env.production up -d --build
sleep 8
docker exec meet_backend php artisan migrate --force
docker exec meet_backend php artisan db:seed --class=MeetSubscriptionPlanSeeder --force
docker exec meet_backend php artisan config:clear
docker exec meet_backend php artisan cache:clear
MEET_HTTP_PORT=8190 bash scripts/setup-apache-meet.sh || true
echo "--- containers ---"
docker ps --filter name=meet_ --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'
echo "--- health ---"
curl -sf http://127.0.0.1:8190/ | head -c 200 || echo FRONTEND_FAIL
curl -sf http://127.0.0.1:8190/api/admin/system/health || echo API_FAIL
"""
    code = run(client, deploy_cmd, timeout=7200)
    client.close()
    return code


if __name__ == "__main__":
    raise SystemExit(main())
