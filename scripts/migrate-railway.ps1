# Run Doctrine migrations against Railway MySQL from your PC (no Railway shell needed).
#
# Usage:
#   1. Railway → MySQL service → Variables → copy MYSQL_PUBLIC_URL
#   2. In PowerShell:
#        cd Backend
#        $env:RAILWAY_DATABASE_URL = "mysql://root:...@kodama.proxy.rlwy.net:PORT/railway"
#        .\scripts\migrate-railway.ps1
#
# Or pass as argument:
#        .\scripts\migrate-railway.ps1 "mysql://root:pass@host:port/railway"

param(
    [string]$DatabaseUrl = $env:RAILWAY_DATABASE_URL
)

$ErrorActionPreference = "Stop"
Set-Location (Join-Path $PSScriptRoot "..")

if (-not $DatabaseUrl) {
    Write-Host ""
    Write-Host "Set your Railway public MySQL URL first:" -ForegroundColor Yellow
    Write-Host '  $env:RAILWAY_DATABASE_URL = "<MYSQL_PUBLIC_URL from Railway>"' -ForegroundColor Cyan
    Write-Host "  .\scripts\migrate-railway.ps1" -ForegroundColor Cyan
    Write-Host ""
    exit 1
}

if ($DatabaseUrl -notmatch "serverVersion=") {
    if ($DatabaseUrl -match "\?") {
        $DatabaseUrl += "&serverVersion=8.0.32&charset=utf8mb4"
    } else {
        $DatabaseUrl += "?serverVersion=8.0.32&charset=utf8mb4"
    }
}

$env:APP_ENV = "prod"
$env:DATABASE_URL = $DatabaseUrl

Write-Host "Creating/updating schema on Railway MySQL (from entities)..." -ForegroundColor Green
php bin/console doctrine:schema:update --force --env=prod

if ($LASTEXITCODE -ne 0) {
    Write-Host ""
    Write-Host "[FAILED] schema:update failed. Check URL, password, and that MySQL is Online." -ForegroundColor Red
    exit $LASTEXITCODE
}

Write-Host ""
Write-Host "[OK] Database schema updated. Refresh Railway MySQL -> Data tab." -ForegroundColor Green
Write-Host "Test: https://backend-webdev-production.up.railway.app/api/products" -ForegroundColor Green
Write-Host ""
Write-Host "Note: doctrine:migrations:migrate may fail on empty Railway DB (missing user table in old migrations)." -ForegroundColor Yellow
Write-Host "schema:update is used instead for cloud deploys." -ForegroundColor Yellow
