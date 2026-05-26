# Reads Backend/.env and prints Railway Raw Editor lines (for copy-paste).
# Run from repo: .\Backend\scripts\export-railway-env.ps1

$ErrorActionPreference = "Stop"
$envFile = Join-Path $PSScriptRoot "..\.env"
if (-not (Test-Path $envFile)) {
    Write-Host "Missing $envFile" -ForegroundColor Red
    exit 1
}

$vars = @{}
Get-Content $envFile | ForEach-Object {
    if ($_ -match '^\s*([^#=]+)=(.*)$') {
        $vars[$matches[1].Trim()] = $matches[2].Trim().Trim('"')
    }
}

$railwayUrl = "https://backend-webdev-production.up.railway.app"
$frontend = $vars['FRONTEND_URL']
if ($frontend -match 'localhost|127\.0\.0\.1') {
    $frontend = "https://YOUR-VERCEL-APP.vercel.app"
    Write-Host "# WARNING: local .env has localhost FRONTEND_URL - replace YOUR-VERCEL-APP below" -ForegroundColor Yellow
}

$mailerDsn = $vars['MAILER_DSN']
if ($mailerDsn -and $mailerDsn -notmatch 'timeout=') {
    $mailerDsn += $(if ($mailerDsn -match '\?') { '&' } else { '?' }) + 'encryption=tls&timeout=10'
}

Write-Host ""
Write-Host "Paste into Railway -> backend-webdev -> Variables -> Raw Editor:" -ForegroundColor Cyan
Write-Host ""
Write-Host "FRONTEND_URL=$frontend"
Write-Host "DEFAULT_URI=$railwayUrl"
Write-Host "GOOGLE_CLIENT_ID=$($vars['GOOGLE_CLIENT_ID'])"
Write-Host "GOOGLE_CLIENT_SECRET=$($vars['GOOGLE_CLIENT_SECRET'])"
Write-Host "GOOGLE_REDIRECT_URI=$railwayUrl/connect/google/check"
Write-Host "MAILER_DSN=$mailerDsn"
Write-Host "MAILER_FROM_ADDRESS=$($vars['MAILER_FROM_ADDRESS'])"
Write-Host 'MAILER_FROM_NAME="K-Dream Merchandise"'
Write-Host "CONTACT_NOTIFY_EMAIL=$($vars['CONTACT_NOTIFY_EMAIL'])"
Write-Host ""
Write-Host "Then: Deploy / Redeploy backend-webdev" -ForegroundColor Green
Write-Host "Verify: $railwayUrl/api/health/deployment-check" -ForegroundColor Green
Write-Host ""
