#!/usr/bin/env bash
# Do not use set -e: bootstrap steps may fail; the web server must still start.
set -uo pipefail

cd "$(dirname "$0")/.."

APP_ENV="${APP_ENV:-prod}"
export APP_ENV

if [ ! -f .env ]; then
  echo "Creating .env from .env.example (Railway variables take precedence)..."
  cp .env.example .env 2>/dev/null || echo "APP_ENV=${APP_ENV}" > .env
fi

echo "Generating JWT keys if missing..."
mkdir -p config/jwt
php bin/console lexik:jwt:generate-keypair --skip-if-exists --env="${APP_ENV}" 2>/dev/null || true

echo "Installing public assets..."
php bin/console assets:install public --no-interaction --env="${APP_ENV}" 2>/dev/null || true

echo "Running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env="${APP_ENV}" 2>/dev/null || true

echo "Warming production cache..."
php bin/console cache:clear --env="${APP_ENV}" --no-warmup 2>/dev/null || true
php bin/console cache:warmup --env="${APP_ENV}" 2>/dev/null || true

PORT="${PORT:-8000}"
echo "Starting PHP on 0.0.0.0:${PORT} (router: public/index.php)..."
# Built-in server must use index.php as router or /api/* returns 404.
exec php -S "0.0.0.0:${PORT}" -t public public/index.php
