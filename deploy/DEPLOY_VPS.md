# Deploy Xander Global Academy on Linux VPS (fully automated)

| App | URL |
|-----|-----|
| Frontend | https://xanderglobalacademy.com |
| API | https://api.xanderglobalacademy.com |

| Repo | URL |
|------|-----|
| Frontend | https://github.com/kass2024/E-earning-Xander-front-end |
| Backend + `deploy/` | https://github.com/kass2024/E-earning-Xander-Backend |

---

## Do not disturb existing Apache sites

Existing `/var/www` folders (`html`, `Marketing`, `parrot-moc`, `whatsap`, `xanderbot`, `xandermock`) stay untouched.

| Rule | Detail |
|------|--------|
| Install path | `/opt/e-learning-xander` only |
| Ports | Docker → **`127.0.0.1:8090`** only |
| Apache | One new vhost: `xander-academy-elearning.conf` |
| Never | Edit `/var/www`, stop Apache, bind Docker to public 80/443 |

---

## One-command deploy (from Windows)

From backend folder:

```powershell
cd C:\methode\water_level\E-Learning-Xander-Final\E-learning-parrot-backend

# First time only — set your VPS SSH target
copy deploy\vps.env.example deploy\vps.env
notepad deploy\vps.env
# VPS_HOST=root@YOUR_VPS_IP
# IMPORT_DB=1

.\deploy\scripts\push-and-deploy.ps1
```

This automatically:

1. Exports local MySQL (`learning-xander`) → `deploy/db/latest.sql.gz`
2. Builds `deploy/.env.production` (academy domains + secrets from local `.env`)
3. Pushes frontend + backend (including `deploy/` folder) to GitHub
4. Uploads `.env.production` + DB dump over SCP (not committed to git)
5. On VPS: `git pull`, Docker build, DB import, Apache proxy

Flags:

```powershell
.\deploy\scripts\push-and-deploy.ps1 -SkipDbImport   # code only, keep remote DB
.\deploy\scripts\push-and-deploy.ps1 -SkipPush       # upload + remote only
.\deploy\scripts\push-and-deploy.ps1 -SkipRemote     # export + push only
```

---

## Manual VPS steps (if needed)

```bash
mkdir -p /opt/e-learning-xander && cd /opt/e-learning-xander
git clone https://github.com/kass2024/E-earning-Xander-Backend.git E-learning-parrot-backend
git clone https://github.com/kass2024/E-earning-Xander-front-end.git E-learning-parrot-frontend

cd E-learning-parrot-backend/deploy
cp env.production.example .env.production
nano .env.production

chmod +x scripts/*.sh
IMPORT_DB=0 ./scripts/vps-deploy.sh
```

HTTPS (once DNS points here):

```bash
sudo certbot --apache -d xanderglobalacademy.com -d www.xanderglobalacademy.com -d api.xanderglobalacademy.com
```

---

## DNS

| Host | Type | Value |
|------|------|--------|
| `@` / `www` | A | VPS IP |
| `api` | A | VPS IP |

---

## Local helpers

| Script | Purpose |
|--------|---------|
| `scripts/export-db.ps1` | Dump XAMPP DB → `db/latest.sql.gz` |
| `scripts/prepare-env.ps1` | Build `.env.production` from local `.env` |
| `scripts/push-and-deploy.ps1` | Full automate |
| `scripts/vps-deploy.sh` | Remote pull/build/import |
| `scripts/setup-apache-proxy.sh` | Safe Apache proxy (academy domains only) |

**Security:** DB dumps and `.env.production` / `vps.env` are gitignored — never pushed to public GitHub.
