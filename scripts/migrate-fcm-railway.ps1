# Idempotent Railway migration: add user.fcm_token for Firebase push notifications.
#
# Usage (from Backend folder):
#   $env:RAILWAY_DATABASE_URL = "<MYSQL_PUBLIC_URL from Railway MySQL service>"
#   .\scripts\migrate-fcm-railway.ps1

param(
    [string]$DatabaseUrl = $env:RAILWAY_DATABASE_URL
)

$ErrorActionPreference = "Stop"
Set-Location (Join-Path $PSScriptRoot "..")

if (-not $DatabaseUrl) {
    Write-Host ""
    Write-Host "Set Railway public MySQL URL first:" -ForegroundColor Yellow
    Write-Host '  $env:RAILWAY_DATABASE_URL = "<MYSQL_PUBLIC_URL from Railway>"' -ForegroundColor Cyan
    Write-Host "  .\scripts\migrate-fcm-railway.ps1" -ForegroundColor Cyan
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

Write-Host "Syncing Railway schema (adds user.fcm_token if missing)..." -ForegroundColor Green
php bin/console doctrine:schema:update --force --no-interaction --env=prod
if ($LASTEXITCODE -ne 0) {
    Write-Host "[FAILED] schema:update failed. Check MYSQL_PUBLIC_URL and DB status." -ForegroundColor Red
    exit $LASTEXITCODE
}

Write-Host "Recording migration version (best effort)..." -ForegroundColor Gray
php bin/console doctrine:migrations:version "DoctrineMigrations\\Version20260528121000" --add --no-interaction --env=prod 2>$null | Out-Null

Write-Host ""
Write-Host "[DONE] Railway DB ready for Firebase push (user.fcm_token)." -ForegroundColor Green
Write-Host "Next: push Backend, redeploy, login on mobile to register FCM token." -ForegroundColor Cyan
