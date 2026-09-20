#!/usr/bin/env python3
"""Deploy paid meeting bookings to Xander e-learning.

Keeps Xander STRIPE_* keys. Copies code, migrates, rebuilds frontend, verifies.
"""
from __future__ import annotations

import sys
import time
from pathlib import Path

import paramiko

ROOT = Path(r"C:\methode\water_level\E-Learning-Xander-Final")
BE = ROOT / "E-learning-parrot-backend"
FE = ROOT / "E-learning-parrot-frontend"
DEPLOY = BE / "deploy"
REMOTE = "/opt/e-learning-xander"

BACKEND_FILES = [
    "app/Models/MeetingPayment.php",
    "app/Services/MeetingBookingPaymentService.php",
    "database/migrations/2026_09_03_140000_add_meeting_booking_payments.php",
    "app/Models/MeetingRegistration.php",
    "app/Models/SiteSetting.php",
    "config/services.php",
    "app/Http/Controllers/Api/PaymentSettingsController.php",
    "app/Http/Controllers/Api/MeetingRegistrationController.php",
    "app/Http/Controllers/Api/PaymentController.php",
    "routes/api.php",
]

FRONTEND_FILES = [
    "src/api/axios.ts",
    "src/components/dashboard/PaymentReceiverSettings.tsx",
    "src/components/meeting/AdminMeetingAvailabilityCalendar.tsx",
    "src/components/meeting/MeetingBookingPaymentStep.tsx",
    "src/pages/MeetingRegistration.tsx",
    "src/pages/dashboard/Appointments.tsx",
]


def load_vps() -> tuple[str, str, str]:
    cfg: dict[str, str] = {}
    for raw in (DEPLOY / "vps.env").read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        cfg[k.strip()] = v.strip()
    host = cfg["VPS_HOST"]
    user, hostname = host.split("@", 1) if "@" in host else ("root", host)
    return user, hostname, cfg["VPS_PASSWORD"]


def connect() -> paramiko.SSHClient:
    user, host, password = load_vps()
    last = None
    for i in range(1, 8):
        try:
            c = paramiko.SSHClient()
            c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
            c.connect(host, username=user, password=password, timeout=90, banner_timeout=90, auth_timeout=90)
            c.get_transport().set_keepalive(15)
            print(f"SSH_OK try={i}", flush=True)
            return c
        except Exception as e:
            last = e
            print(f"SSH_TRY_{i} {type(e).__name__}: {e}", flush=True)
            time.sleep(4 * i)
    raise RuntimeError(f"SSH failed: {last}")


def run(c: paramiko.SSHClient, cmd: str, timeout: int = 1800) -> int:
    print(f"\n$ {cmd.strip().splitlines()[0][:160]}", flush=True)
    _, stdout, _ = c.exec_command(cmd, get_pty=True, timeout=timeout)
    ch = stdout.channel
    while True:
        while ch.recv_ready():
            sys.stdout.buffer.write(ch.recv(8192))
            sys.stdout.buffer.flush()
        if ch.exit_status_ready() and not ch.recv_ready():
            break
        time.sleep(0.05)
    code = ch.recv_exit_status()
    print(f"EXIT {code}", flush=True)
    return code


def put_lf(sftp: paramiko.SFTPClient, local: Path, remote: str) -> None:
    data = local.read_bytes().replace(b"\r\n", b"\n").replace(b"\r", b"\n")
    tmp = local.with_suffix(local.suffix + ".lf")
    tmp.write_bytes(data)
    try:
        sftp.put(str(tmp), remote)
    finally:
        tmp.unlink(missing_ok=True)


def main() -> int:
    missing = [BE / rel for rel in BACKEND_FILES if not (BE / rel).is_file()]
    missing += [FE / rel for rel in FRONTEND_FILES if not (FE / rel).is_file()]
    if missing:
        print("MISSING", missing)
        return 1

    c = connect()
    sftp = c.open_sftp()

    for rel in BACKEND_FILES:
        remote = f"{REMOTE}/E-learning-parrot-backend/{rel}"
        print("PUT", rel, flush=True)
        run(c, f"mkdir -p {remote.rsplit('/', 1)[0]}", timeout=30)
        put_lf(sftp, BE / rel, remote)

    for rel in FRONTEND_FILES:
        remote = f"{REMOTE}/E-learning-parrot-frontend/{rel}"
        print("PUT", rel, flush=True)
        run(c, f"mkdir -p {remote.rsplit('/', 1)[0]}", timeout=30)
        put_lf(sftp, FE / rel, remote)

    sftp.close()

    cmd = r"""
set -e
REMOTE=/opt/e-learning-xander
B=xander_backend
S=xander_scheduler
F=xander_frontend

echo '=== HOT COPY BACKEND ==='
for f in \
  app/Models/MeetingPayment.php \
  app/Services/MeetingBookingPaymentService.php \
  database/migrations/2026_09_03_140000_add_meeting_booking_payments.php \
  app/Models/MeetingRegistration.php \
  app/Models/SiteSetting.php \
  config/services.php \
  app/Http/Controllers/Api/PaymentSettingsController.php \
  app/Http/Controllers/Api/MeetingRegistrationController.php \
  app/Http/Controllers/Api/PaymentController.php \
  routes/api.php
do
  docker cp "$REMOTE/E-learning-parrot-backend/$f" "$B:/var/www/html/$f"
  if docker ps --format '{{.Names}}' | grep -qx "$S"; then
    docker cp "$REMOTE/E-learning-parrot-backend/$f" "$S:/var/www/html/$f" || true
  fi
  echo copied:$f
done

echo '=== MEETING FEE ENV ONLY (do not touch STRIPE_*) ==='
ensure_fee() {
  local file="$1"
  docker exec "$B" sh -c "test -f $file || touch $file"
  if ! docker exec "$B" grep -q '^MEETING_BOOKING_FEE_USD=' "$file"; then
    docker exec "$B" sh -c "echo 'MEETING_BOOKING_FEE_USD=10' >> $file"
  fi
  if ! docker exec "$B" grep -q '^MEETING_BOOKING_FEE_RWF=' "$file"; then
    docker exec "$B" sh -c "echo 'MEETING_BOOKING_FEE_RWF=10000' >> $file"
  fi
}
ensure_fee /var/www/html/.env
if [ -f /opt/e-learning-xander/E-learning-parrot-backend/deploy/.env.production ]; then
  ENVF=/opt/e-learning-xander/E-learning-parrot-backend/deploy/.env.production
  grep -q '^MEETING_BOOKING_FEE_USD=' "$ENVF" || echo 'MEETING_BOOKING_FEE_USD=10' >> "$ENVF"
  grep -q '^MEETING_BOOKING_FEE_RWF=' "$ENVF" || echo 'MEETING_BOOKING_FEE_RWF=10000' >> "$ENVF"
fi
docker exec "$B" sh -c "grep -E '^STRIPE_(SECRET|PUBLIC)_KEY=' /var/www/html/.env | sed 's/=.*/=***xander***/'"
docker exec "$B" sh -c "grep -E '^MEETING_BOOKING_FEE_' /var/www/html/.env || true"

echo '=== MIGRATE + CLEAR ==='
docker exec "$B" sh -c '
php artisan migrate --force --no-interaction
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php -r "function_exists(\"opcache_reset\") && opcache_reset();" || true
'

echo '=== FRONTEND BUILD ==='
cd "$REMOTE/E-learning-parrot-backend/deploy"
docker compose -f docker-compose.prod.yml --env-file .env.production up -d --build --no-deps frontend >/tmp/xander-meeting-pay-fe.log 2>&1
echo FE_EXIT:$?
tail -n 50 /tmp/xander-meeting-pay-fe.log

echo '=== VERIFY STRINGS ==='
docker exec "$B" php artisan tinker --execute="echo 'svc='.(class_exists('App\\\\Services\\\\MeetingBookingPaymentService')?'yes':'no').' tbl='.(\\Illuminate\\\\Support\\\\Facades\\\\Schema::hasTable('meeting_payments')?'yes':'no').' col='.(\\Illuminate\\\\Support\\\\Facades\\\\Schema::hasColumn('meeting_registrations','payment_status')?'yes':'no').' stripe_xander='.(str_contains((string)config('services.stripe.secret'),'SfEcq')?'yes':'no');"

echo '=== HTTP ==='
curl -sk -o /dev/null -w 'up:%{http_code}\n' --resolve api.e-learning.school:443:127.0.0.1 https://api.e-learning.school/api/admin/system/health || true
curl -sk -o /dev/null -w 'mtgcfg:%{http_code}\n' --resolve api.e-learning.school:443:127.0.0.1 https://api.e-learning.school/api/admin/payments/meeting/config || true
curl -s -o /dev/null -w 'local8090:%{http_code}\n' -H 'Host: www.e-learning.school' http://127.0.0.1:8090/meeting-registration || true
docker exec "$F" sh -c "grep -R -m1 -a 'Pay to confirm booking' /usr/share/nginx/html >/dev/null 2>&1 && echo fe_pay_text:yes || echo fe_pay_text:no"
echo DONE
"""
    code = run(c, cmd, timeout=3600)
    c.close()
    return code


if __name__ == "__main__":
    raise SystemExit(main())
