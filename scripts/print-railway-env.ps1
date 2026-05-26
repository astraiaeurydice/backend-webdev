# Prints Railway variable names to set (copy values from your local .env into Railway dashboard).
# Does not print secret values to the console.

Write-Host "Set these on Railway -> backend-webdev -> Variables:" -ForegroundColor Cyan
Write-Host ""
$vars = @(
    "FRONTEND_URL=https://YOUR-APP.vercel.app",
    "DEFAULT_URI=https://backend-webdev-production.up.railway.app",
    "GOOGLE_CLIENT_ID=(from .env)",
    "GOOGLE_CLIENT_SECRET=(from .env)",
    "GOOGLE_REDIRECT_URI=https://backend-webdev-production.up.railway.app/connect/google/check",
    "MAILER_DSN=(Brevo SMTP from .env)",
    "MAILER_FROM_ADDRESS=(from .env)",
    "MAILER_FROM_NAME=K-Dream Merchandise",
    "CONTACT_NOTIFY_EMAIL=(from .env)"
)
$vars | ForEach-Object { Write-Host "  $_" }
Write-Host ""
Write-Host "Google Console -> Authorized redirect URI (exact):" -ForegroundColor Yellow
Write-Host "  https://backend-webdev-production.up.railway.app/connect/google/check"
Write-Host ""
Write-Host "After deploy, verify:" -ForegroundColor Green
Write-Host "  https://backend-webdev-production.up.railway.app/api/health/integrations"
