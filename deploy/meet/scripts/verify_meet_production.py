#!/usr/bin/env python3
"""Verify Xander Meet production: users, login, health."""
from __future__ import annotations

import json
import sys
import urllib.request
from pathlib import Path

import paramiko

DEPLOY = Path(__file__).resolve().parents[2]
cfg = {}
for line in (DEPLOY / "vps.env").read_text().splitlines():
    if "=" in line and not line.strip().startswith("#"):
        k, v = line.split("=", 1)
        cfg[k.strip()] = v.strip()


def ssh_users() -> str:
    c = paramiko.SSHClient()
    c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    c.connect("66.29.135.120", username="root", password=cfg["VPS_PASSWORD"], timeout=30)
    cmd = "docker exec meet_backend php artisan tinker --execute=\"echo App\\\\Models\\\\User::count();\""
    _, stdout, stderr = c.exec_command(cmd, timeout=120)
    count = stdout.read().decode().strip()
    cmd2 = (
        'docker exec meet_backend php -r '
        '"require \'vendor/autoload.php\'; $app=require \'bootstrap/app.php\'; $app->make(Illuminate\\\\Contracts\\\\Console\\\\Kernel::class)->bootstrap(); '
        'foreach(App\\\\Models\\\\User::orderBy(\'role\')->orderBy(\'id\')->get([\'id\',\'name\',\'email\',\'role\',\'status\']) as $u){'
        'echo $u->id.\'|\'.$u->role.\'|\'.$u->email.\'|\'.$u->name.PHP_EOL;}"'
    )
    _, stdout2, stderr2 = c.exec_command(cmd2, timeout=120)
    out = f"count={count}\n" + stdout2.read().decode() + stderr2.read().decode()
    c.close()
    return out


def test_login(email: str, password: str) -> dict:
    data = json.dumps({"username": email, "password": password}).encode()
    req = urllib.request.Request(
        "https://meet.xandertech.llc/api/admin/auth/login",
        data=data,
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.loads(resp.read().decode())


def main() -> int:
    print("=== USERS ===")
    print(ssh_users())
    print("\n=== LOGIN TEST (admin) ===")
    try:
        r = test_login("info@xanderglobalscholars.com", "Xander@2026")
        print(json.dumps({k: r.get(k) for k in ("token", "user") if k in r}, indent=2)[:800])
        print("login_ok:", "token" in r or "access_token" in r)
    except Exception as e:
        print("login_failed:", e)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
