# Export local XAMPP/MySQL DB → deploy/db/latest.sql.gz
# Run from anywhere:  .\deploy\scripts\export-db.ps1
$ErrorActionPreference = "Stop"

$Deploy = Split-Path $PSScriptRoot -Parent
$Backend = Split-Path $Deploy -Parent
$EnvFile = Join-Path $Backend ".env"
$OutDir = Join-Path $Deploy "db"
New-Item -ItemType Directory -Path $OutDir -Force | Out-Null

if (-not (Test-Path $EnvFile)) { throw "Missing $EnvFile" }

function Get-DotEnvValue([string]$Path, [string]$Key) {
    $line = Get-Content $Path | Where-Object { $_ -match "^\s*$Key\s*=" } | Select-Object -First 1
    if (-not $line) { return "" }
    $val = ($line -split "=", 2)[1].Trim().Trim('"').Trim("'")
    return $val
}

$DbName = (Get-DotEnvValue $EnvFile "DB_DATABASE").Trim()
$DbUser = Get-DotEnvValue $EnvFile "DB_USERNAME"
$DbPass = Get-DotEnvValue $EnvFile "DB_PASSWORD"
$DbHost = Get-DotEnvValue $EnvFile "DB_HOST"
$DbPort = Get-DotEnvValue $EnvFile "DB_PORT"
if (-not $DbHost) { $DbHost = "127.0.0.1" }
if (-not $DbPort) { $DbPort = "3306" }
if (-not $DbUser) { $DbUser = "root" }
if (-not $DbName) { throw "DB_DATABASE empty in .env" }

$Mysqldump = $null
foreach ($c in @(
    "C:\xampp\mysql\bin\mysqldump.exe",
    "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe",
    (Get-Command mysqldump -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source)
)) {
    if ($c -and (Test-Path $c)) { $Mysqldump = $c; break }
}
if (-not $Mysqldump) { throw "mysqldump not found (install XAMPP or MySQL client)" }

$Stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$Raw = Join-Path $OutDir "learning-xander-$Stamp.sql"
$Gz = Join-Path $OutDir "latest.sql.gz"

Write-Host "==> Dumping database '$DbName' from ${DbHost}:${DbPort}" -ForegroundColor Cyan

$argList = @(
    "-h$DbHost", "-P$DbPort", "-u$DbUser",
    "--single-transaction", "--routines", "--triggers", "--hex-blob",
    $DbName
)
if ($DbPass) { $env:MYSQL_PWD = $DbPass }
try {
    $p = Start-Process -FilePath $Mysqldump -ArgumentList $argList -RedirectStandardOutput $Raw -RedirectStandardError (Join-Path $OutDir "mysqldump.err") -Wait -PassThru -NoNewWindow
    if ($p.ExitCode -ne 0) {
        $err = Get-Content (Join-Path $OutDir "mysqldump.err") -Raw -ErrorAction SilentlyContinue
        throw "mysqldump failed (exit $($p.ExitCode)): $err"
    }
} finally {
    Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
    Remove-Item (Join-Path $OutDir "mysqldump.err") -ErrorAction SilentlyContinue
}

if (-not (Test-Path $Raw) -or (Get-Item $Raw).Length -lt 100) {
    throw "Dump failed or empty: $Raw"
}

# Compress with .NET (no gzip.exe required)
Add-Type -AssemblyName System.IO.Compression
$inBytes = [System.IO.File]::ReadAllBytes($Raw)
$outStream = [System.IO.File]::Create($Gz)
$gzip = New-Object System.IO.Compression.GZipStream($outStream, [System.IO.Compression.CompressionMode]::Compress)
$gzip.Write($inBytes, 0, $inBytes.Length)
$gzip.Close()
$outStream.Close()
Remove-Item $Raw -Force

Write-Host "Wrote $Gz ($([math]::Round((Get-Item $Gz).Length/1MB, 2)) MB)" -ForegroundColor Green
