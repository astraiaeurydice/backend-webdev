# Start local dev stack for API + WebSocket (run each block in its own terminal, or use this as a checklist).
#
# Terminal A: docker compose up -d mysql
# Terminal B: php -S 0.0.0.0:8000 -t public public/index.php
# Terminal C: php bin/websocket-server.php start
#
# Optional: test internal push (user must be connected on mobile first)
#   Invoke-WebRequest -Uri http://127.0.0.1:8091 -Method POST -ContentType application/json `
#     -Body '{"userId":1,"payload":{"type":"test","title":"Hi","body":"WS test"}}'

$ErrorActionPreference = "Stop"
Set-Location (Join-Path $PSScriptRoot "..")

Write-Host "Checking ports..." -ForegroundColor Cyan
foreach ($port in 8000, 8080, 8091) {
    $inUse = Get-NetTCPConnection -LocalPort $port -ErrorAction SilentlyContinue
    if ($inUse) { Write-Host "  Port $port : in use" -ForegroundColor Yellow }
    else { Write-Host "  Port $port : free" -ForegroundColor Green }
}

Write-Host ""
Write-Host "Start manually in separate terminals:" -ForegroundColor Cyan
Write-Host "  1. php -S 0.0.0.0:8000 -t public public/index.php"
Write-Host "  2. php bin/websocket-server.php start"
Write-Host ""
Write-Host "Mobile: API_ENV=local (default in __DEV__), see Kpop/DEV-NOTIFICATIONS.md"
