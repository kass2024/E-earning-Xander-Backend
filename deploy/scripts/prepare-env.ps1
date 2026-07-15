# Build deploy/.env.production from local backend .env + academy production domains.
# Secrets stay local (file is gitignored).
$ErrorActionPreference = "Stop"

$Deploy = Split-Path $PSScriptRoot -Parent
$Backend = Split-Path $Deploy -Parent
$Src = Join-Path $Backend ".env"
$Example = Join-Path $Deploy "env.production.example"
$Dest = Join-Path $Deploy ".env.production"

if (-not (Test-Path $Src)) { throw "Missing $Src" }
if (-not (Test-Path $Example)) { throw "Missing $Example" }

function Get-DotEnvMap([string]$Path) {
    $map = @{}
    Get-Content $Path | ForEach-Object {
        $line = $_.Trim()
        if (-not $line -or $line.StartsWith("#")) { return }
        $i = $line.IndexOf("=")
        if ($i -lt 1) { return }
        $k = $line.Substring(0, $i).Trim()
        $v = $line.Substring($i + 1).Trim()
        $map[$k] = $v
    }
    return $map
}

$local = Get-DotEnvMap $Src
$lines = Get-Content $Example

$override = @{
    "APP_ENV"           = "production"
    "APP_DEBUG"         = "false"
    "APP_URL"           = "https://api.xanderglobalacademy.com"
    "FRONTEND_URL"      = "https://xanderglobalacademy.com"
    "VITE_API_URL"      = "https://api.xanderglobalacademy.com/api/admin"
    "VITE_APP_NAME"     = "Xander Learning Hub"
    "VITE_APP_BUILD_ID" = (Get-Date -Format "yyyy-MM-dd-HHmm")
    "DB_HOST"           = "mysql"
    "DB_PORT"           = "3306"
    "DB_DATABASE"       = "learning_xander"
    "DB_USERNAME"       = "parrot"
    "AUTO_MIGRATE"      = "true"
    "AUTO_SEED_DEMO"    = "false"
    "PARROT_HTTP_PORT"  = "8090"
    "MAIL_HOST"         = "xanderglobalacademy.com"
}

# Carry secrets / keys from local when present
foreach ($k in @(
    "APP_KEY", "APP_NAME",
    "ZOOM_ACCOUNT_ID", "ZOOM_CLIENT_ID", "ZOOM_CLIENT_SECRET", "ZOOM_HOST_USER_ID",
    "ZOOM_EMBED_CLIENT_ID", "ZOOM_EMBED_CLIENT_SECRET",
    "STRIPE_SECRET_KEY", "STRIPE_PUBLIC_KEY",
    "PCLOUD_ACCESS_TOKEN", "PCLOUD_ROOT_FOLDER_ID", "PCLOUD_API_URL",
    "MAIL_USERNAME", "MAIL_PASSWORD", "MAIL_FROM_ADDRESS", "MAIL_FROM_NAME",
    "MAIL_MAILER", "MAIL_PORT", "MAIL_SCHEME", "MAIL_ENCRYPTION",
    "SEED_PLATFORM_PASSWORD", "PLATFORM_ADMIN_EMAIL", "MIGRATE_TOKEN"
)) {
    if ($local.ContainsKey($k) -and $local[$k]) { $override[$k] = $local[$k] }
}

if (-not $override.ContainsKey("MYSQL_ROOT_PASSWORD") -or -not $override["MYSQL_ROOT_PASSWORD"]) {
    $override["MYSQL_ROOT_PASSWORD"] = -join ((48..57) + (65..90) + (97..122) | Get-Random -Count 24 | ForEach-Object { [char]$_ })
}
if (-not $override.ContainsKey("DB_PASSWORD") -or -not $override["DB_PASSWORD"] -or $override["DB_PASSWORD"] -eq "change_db_password") {
    $override["DB_PASSWORD"] = -join ((48..57) + (65..90) + (97..122) | Get-Random -Count 20 | ForEach-Object { [char]$_ })
}

$out = foreach ($line in $lines) {
    if ($line -match '^\s*#' -or $line -match '^\s*$') { $line; continue }
    $i = $line.IndexOf("=")
    if ($i -lt 1) { $line; continue }
    $k = $line.Substring(0, $i).Trim()
    if ($override.ContainsKey($k)) { "$k=$($override[$k])" } else { $line }
}

# Ensure critical keys exist
foreach ($k in $override.Keys) {
    if (-not ($out | Where-Object { $_ -match "^$k=" })) {
        $out += "$k=$($override[$k])"
    }
}

$out | Set-Content -Path $Dest -Encoding utf8
Write-Host "Wrote $Dest (gitignored). Domains: xanderglobalacademy.com / api.xanderglobalacademy.com" -ForegroundColor Green
