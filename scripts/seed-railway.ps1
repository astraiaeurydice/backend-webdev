# Seed Railway MySQL with initial data (users via fixtures; products must be added in admin or imported).
#
# Usage:
#   $env:RAILWAY_DATABASE_URL = "<MYSQL_PUBLIC_URL from Railway MySQL Variables>"
#   .\scripts\seed-railway.ps1

param(
    [string]$DatabaseUrl = $env:RAILWAY_DATABASE_URL
)

$ErrorActionPreference = "Stop"
Set-Location (Join-Path $PSScriptRoot "..")

if (-not $DatabaseUrl) {
    Write-Host 'Set: $env:RAILWAY_DATABASE_URL = "mysql://root:...@host:port/railway"' -ForegroundColor Yellow
    exit 1
}

if ($DatabaseUrl -notmatch "serverVersion=") {
    if ($DatabaseUrl -match "\?") { $DatabaseUrl += "&serverVersion=8.0.32&charset=utf8mb4" }
    else { $DatabaseUrl += "?serverVersion=8.0.32&charset=utf8mb4" }
}

$env:DATABASE_URL = $DatabaseUrl
# Fixtures bundle is only registered in dev — use dev env to load, still writes to Railway DB.
$env:APP_ENV = "dev"

Write-Host "Railway table counts (before seed):" -ForegroundColor Cyan
php bin/console dbal:run-sql "SELECT 'user' t, COUNT(*) c FROM user UNION SELECT 'product', COUNT(*) FROM product" --env=dev 2>$null

Write-Host ""
Write-Host "Loading UserFixtures (admin user)..." -ForegroundColor Green
php bin/console doctrine:fixtures:load --no-interaction --env=dev --group=default 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Host "Trying app:create-admin instead..." -ForegroundColor Yellow
    php bin/console app:create-admin admin@ako.com admin123 adminako --env=dev 2>&1 | Out-Null
}

Write-Host ""
Write-Host "Creating staff user..." -ForegroundColor Green
php bin/console app:create-staff staff@ako.com staff123 staffako --env=dev 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Host "(Staff may already exist — skip if email is taken.)" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "Railway table counts (after seed):" -ForegroundColor Cyan
php bin/console dbal:run-sql "SELECT 'user' t, COUNT(*) c FROM user UNION SELECT 'product', COUNT(*) FROM product" --env=dev 2>$null
php bin/console dbal:run-sql "SELECT email, username, roles FROM user WHERE email IN ('admin@ako.com','staff@ako.com')" --env=dev 2>$null

Write-Host ""
Write-Host "Default accounts:" -ForegroundColor Green
Write-Host "  Admin: admin@ako.com  / admin123  (username: adminako)"
Write-Host "  Staff: staff@ako.com  / staff123  (username: staffako)"
Write-Host ""
Write-Host "Products are NOT seeded automatically. Add them via admin panel or import from local MySQL." -ForegroundColor Yellow
