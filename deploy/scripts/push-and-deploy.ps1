# One-command local automation:
#  1) Export local DB
#  2) Prepare .env.production (domains: xanderglobalacademy.com)
#  3) Push frontend + backend (deploy/ lives in backend repo)
#  4) Upload DB dump + .env.production to VPS
#  5) SSH run vps-deploy.sh (pull, build, import DB, Apache proxy)
#
# Usage:
#   copy deploy\vps.env.example deploy\vps.env   # set VPS_HOST
#   .\deploy\scripts\push-and-deploy.ps1
#   .\deploy\scripts\push-and-deploy.ps1 -SkipDbImport   # code only
#   .\deploy\scripts\push-and-deploy.ps1 -SkipPush       # upload+remote only

param(
    [switch]$SkipDbExport,
    [switch]$SkipDbImport,
    [switch]$SkipPush,
    [switch]$SkipRemote
)

$ErrorActionPreference = "Stop"
$Deploy = Split-Path $PSScriptRoot -Parent
$Backend = Split-Path $Deploy -Parent
$Root = Split-Path $Backend -Parent
$Frontend = Join-Path $Root "E-learning-parrot-frontend"
$VpsEnv = Join-Path $Deploy "vps.env"

Write-Host "=== Xander Academy — automate deploy ===" -ForegroundColor Cyan
Write-Host "Front: https://xanderglobalacademy.com"
Write-Host "API:   https://api.xanderglobalacademy.com"
Write-Host "Safe:  /opt only — never /var/www"

if (-not (Test-Path $VpsEnv)) {
    Copy-Item (Join-Path $Deploy "vps.env.example") $VpsEnv
    Write-Host "Created $VpsEnv — set VPS_HOST=root@YOUR_IP then re-run." -ForegroundColor Yellow
    exit 1
}

function Get-VpsMap([string]$Path) {
    $map = @{}
    Get-Content $Path | ForEach-Object {
        $line = $_.Trim()
        if (-not $line -or $line.StartsWith("#")) { return }
        $i = $line.IndexOf("=")
        if ($i -lt 1) { return }
        $map[$line.Substring(0, $i).Trim()] = $line.Substring($i + 1).Trim()
    }
    return $map
}

$cfg = Get-VpsMap $VpsEnv
$VpsHost = $cfg["VPS_HOST"]
$VpsPath = if ($cfg["VPS_PATH"]) { $cfg["VPS_PATH"] } else { "/opt/e-learning-xander" }
$ImportDb = if ($SkipDbImport) { "0" } elseif ($cfg["IMPORT_DB"]) { $cfg["IMPORT_DB"] } else { "1" }
$SshKey = $cfg["SSH_KEY"]

if (-not $VpsHost -or $VpsHost -match "YOUR_VPS") {
    throw "Edit $VpsEnv and set VPS_HOST=root@YOUR_IP"
}

$sshArgs = @()
if ($SshKey) { $sshArgs += @("-i", $SshKey) }
$scpArgs = @()
if ($SshKey) { $scpArgs += @("-i", $SshKey) }

# --- 1) DB export ---
if (-not $SkipDbExport) {
    & (Join-Path $PSScriptRoot "export-db.ps1")
}

# --- 2) Env ---
& (Join-Path $PSScriptRoot "prepare-env.ps1")

# --- 3) Git push ---
if (-not $SkipPush) {
    Write-Host "==> Push frontend" -ForegroundColor Cyan
    Push-Location $Frontend
    if (Test-Path .git) {
        git add -A
        $st = git status --porcelain
        if ($st) {
            git commit -m "chore: sync frontend for xanderglobalacademy.com VPS deploy"
            git push origin main
        } else {
            git push origin main
        }
    } else { throw "Frontend is not a git repo: $Frontend" }
    Pop-Location

    Write-Host "==> Push backend (includes deploy/)" -ForegroundColor Cyan
    Push-Location $Backend
    git add deploy Dockerfile docker
    $st = git status --porcelain
    if ($st) {
        git commit -m "feat: automate VPS deploy for xanderglobalacademy.com (Docker + DB import)"
        git push origin main
    } else {
        git push origin main
    }
    Pop-Location
}

if ($SkipRemote) {
    Write-Host "SkipRemote set — local export/push done." -ForegroundColor Green
    exit 0
}

# --- 4) Ensure remote dirs + clone if needed ---
Write-Host "==> Ensure VPS directories under $VpsPath (not /var/www)" -ForegroundColor Cyan
$remoteBoot = @"
set -e
mkdir -p '$VpsPath'
cd '$VpsPath'
if [ ! -d E-learning-parrot-backend/.git ]; then
  git clone https://github.com/kass2024/E-earning-Xander-Backend.git E-learning-parrot-backend
fi
if [ ! -d E-learning-parrot-frontend/.git ]; then
  git clone https://github.com/kass2024/E-earning-Xander-front-end.git E-learning-parrot-frontend
fi
mkdir -p E-learning-parrot-backend/deploy/db
echo OK_BOOT
"@
& ssh @sshArgs $VpsHost $remoteBoot

# --- 5) Upload secrets + DB (never via public git) ---
Write-Host "==> Upload .env.production + DB dump" -ForegroundColor Cyan
$RemoteDeploy = "$VpsPath/E-learning-parrot-backend/deploy"
& scp @scpArgs (Join-Path $Deploy ".env.production") "${VpsHost}:${RemoteDeploy}/.env.production"
$DumpGz = Join-Path $Deploy "db\latest.sql.gz"
if ((Test-Path $DumpGz) -and $ImportDb -eq "1") {
    & scp @scpArgs $DumpGz "${VpsHost}:${RemoteDeploy}/db/latest.sql.gz"
}

# --- 6) Remote deploy ---
Write-Host "==> Remote vps-deploy.sh" -ForegroundColor Cyan
$remoteCmd = "IMPORT_DB=$ImportDb bash '$RemoteDeploy/scripts/vps-deploy.sh'"
& ssh @sshArgs $VpsHost $remoteCmd

Write-Host ""
Write-Host "DONE." -ForegroundColor Green
Write-Host "  Front: https://xanderglobalacademy.com"
Write-Host "  API:   https://api.xanderglobalacademy.com"
Write-Host "  /var/www projects were not touched."
