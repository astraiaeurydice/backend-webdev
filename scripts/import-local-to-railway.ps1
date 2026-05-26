# Export local Docker MySQL (project_db) and import into Railway MySQL.
#
# Prerequisites:
#   - Docker running, local mysql service up (docker compose up -d mysql)
#   - $env:RAILWAY_DATABASE_URL = MYSQL_PUBLIC_URL from Railway
#
# Usage:
#   $env:RAILWAY_DATABASE_URL = "mysql://root:...@host:port/railway"
#   .\scripts\import-local-to-railway.ps1

param(
    [string]$RailwayUrl = $env:RAILWAY_DATABASE_URL,
    [string]$DumpFile = (Join-Path $PSScriptRoot "railway-seed.sql")
)

$ErrorActionPreference = "Stop"
Set-Location (Join-Path $PSScriptRoot "..")

$localDb = "project_db"
$localUser = "project_user"
$localPass = "project_password"

if (-not $RailwayUrl) {
    Write-Host 'Set: $env:RAILWAY_DATABASE_URL = "<MYSQL_PUBLIC_URL from Railway>"' -ForegroundColor Yellow
    exit 1
}

# Parse mysql://user:pass@host:port/db
if ($RailwayUrl -match '^mysql://([^:]+):([^@]+)@([^:]+):(\d+)/([^?]+)') {
    $rwUser = $Matches[1]
    $rwPass = $Matches[2]
    $rwHost = $Matches[3]
    $rwPort = $Matches[4]
    $rwDb = $Matches[5]
} else {
    Write-Host "Could not parse RAILWAY_DATABASE_URL" -ForegroundColor Red
    exit 1
}

Write-Host "Checking local MySQL (Docker)..." -ForegroundColor Cyan
$container = (docker compose ps -q mysql 2>$null)
if (-not $container) {
    Write-Host "Starting mysql container..." -ForegroundColor Yellow
    docker compose up -d mysql | Out-Null
    Start-Sleep -Seconds 8
    $container = (docker compose ps -q mysql)
}
if (-not $container) {
    Write-Host "[FAILED] Local MySQL container not running. Run: docker compose up -d mysql" -ForegroundColor Red
    exit 1
}

Write-Host "Exporting local database to $DumpFile ..." -ForegroundColor Green
docker compose exec -T mysql mysqldump -u $localUser "-p$localPass" `
    --single-transaction --no-tablespaces --routines --triggers `
    --add-drop-table $localDb | Set-Content -Path $DumpFile -Encoding utf8

if (-not (Test-Path $DumpFile) -or (Get-Item $DumpFile).Length -lt 100) {
    Write-Host "[FAILED] Dump file empty or missing." -ForegroundColor Red
    exit 1
}
Write-Host "Dump size: $((Get-Item $DumpFile).Length) bytes" -ForegroundColor Cyan

Write-Host "Importing into Railway (${rwHost}:${rwPort}/${rwDb}) ..." -ForegroundColor Green
Get-Content $DumpFile -Raw | docker run --rm -i mysql:8.0 mysql `
    -h $rwHost -P $rwPort -u $rwUser "-p$rwPass" $rwDb

if ($LASTEXITCODE -ne 0) {
    Write-Host "[FAILED] Import failed. Check Railway URL and that MySQL is Online." -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "Verifying row counts on Railway..." -ForegroundColor Cyan
$env:DATABASE_URL = if ($RailwayUrl -match '\?') { "$RailwayUrl&serverVersion=8.0.32&charset=utf8mb4" } else { "$RailwayUrl?serverVersion=8.0.32&charset=utf8mb4" }
php bin/console dbal:run-sql "SELECT 'user' t, COUNT(*) c FROM user UNION SELECT 'product', COUNT(*) FROM product UNION SELECT 'custom_order', COUNT(*) FROM custom_order" --env=prod 2>$null

Write-Host ""
Write-Host "[OK] Import complete. Test: https://backend-webdev-production.up.railway.app/api/products" -ForegroundColor Green
Write-Host "Note: uploaded image files on Railway disk may still need re-upload unless paths are URLs." -ForegroundColor Yellow
