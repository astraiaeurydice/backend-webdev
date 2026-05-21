#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

echo "Running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration || true

echo "Warming production cache..."
php bin/console cache:clear --env="${APP_ENV:-prod}" --no-warmup 2>/dev/null || true
php bin/console cache:warmup --env="${APP_ENV:-prod}" 2>/dev/null || true

PORT="${PORT:-8000}"
echo "Starting PHP built-in server on 0.0.0.0:${PORT}..."
exec php -S "0.0.0.0:${PORT}" -t public
