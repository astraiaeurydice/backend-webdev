#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

# Symfony expects a .env file; secrets come from Railway Variables (they override .env values).
if [ ! -f .env ]; then
  echo "Creating .env from .env.example (Railway variables take precedence)..."
  cp .env.example .env
fi

echo "Generating JWT keys if missing..."
mkdir -p config/jwt
php bin/console lexik:jwt:generate-keypair --skip-if-exists 2>/dev/null || true

echo "Installing public assets..."
php bin/console assets:install public --no-interaction --env="${APP_ENV:-prod}" 2>/dev/null || true

echo "Running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env="${APP_ENV:-prod}" || true

echo "Warming production cache..."
php bin/console cache:clear --env="${APP_ENV:-prod}" --no-warmup || true
php bin/console cache:warmup --env="${APP_ENV:-prod}" || true

PORT="${PORT:-8000}"
echo "Starting PHP built-in server on 0.0.0.0:${PORT}..."
exec php -S "0.0.0.0:${PORT}" -t public
