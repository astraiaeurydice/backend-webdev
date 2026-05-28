#!/usr/bin/env bash
# Do not use set -e: bootstrap steps may fail; the web server must still start.
set -uo pipefail

cd "$(dirname "$0")/.."

APP_ENV="${APP_ENV:-prod}"
export APP_ENV

if [ ! -f .env ]; then
  echo "Creating minimal .env (use Railway Variables for secrets; do not commit .env)..."
  cat > .env <<'EOF'
APP_ENV=prod
###> symfony/framework-bundle ###
APP_SECRET=
JWT_SECRET=
FRONTEND_URL=
DEFAULT_URI=
###< symfony/framework-bundle ###
###> doctrine/doctrine-bundle ###
DATABASE_URL=
###< doctrine/doctrine-bundle ###
EOF
fi

# Legacy deploys copied .env.example with unquoted spaces (Symfony Dotenv fatal error).
if [ -f .env ] && grep -q '^MAILER_FROM_NAME=K-Dream Merchandise$' .env 2>/dev/null; then
  echo "Repairing MAILER_FROM_NAME quotes in existing .env..."
  sed -i 's/^MAILER_FROM_NAME=K-Dream Merchandise$/MAILER_FROM_NAME="K-Dream Merchandise"/' .env 2>/dev/null || \
    perl -pi -e 's/^MAILER_FROM_NAME=K-Dream Merchandise$/MAILER_FROM_NAME="K-Dream Merchandise"/' .env
fi

echo "Generating JWT keys if missing..."
mkdir -p config/jwt
php bin/console lexik:jwt:generate-keypair --skip-if-exists --env="${APP_ENV}" 2>/dev/null || true

echo "Installing public assets..."
php bin/console assets:install public --no-interaction --env="${APP_ENV}" 2>/dev/null || true

echo "Running database migrations..."
if ! php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env="${APP_ENV}"; then
  echo "WARN: doctrine:migrations:migrate failed — syncing schema from entities..."
  php bin/console doctrine:schema:update --force --no-interaction --env="${APP_ENV}" 2>/dev/null || true
fi
# Ensure entity-only columns (e.g. receipt_number) exist even when migrations are out of sync
php bin/console doctrine:schema:update --force --no-interaction --env="${APP_ENV}" 2>/dev/null || true

echo "Checking integration env (Google / Brevo / Vercel)..."
php bin/console dbal:run-sql "SELECT 1" --env="${APP_ENV}" >/dev/null 2>&1 || true
php -r '
$fe = getenv("FRONTEND_URL") ?: "";
$dsn = getenv("MAILER_DSN") ?: "";
$gid = getenv("GOOGLE_CLIENT_ID") ?: "";
if (str_contains($fe, "localhost") || $fe === "") {
  echo "WARN: FRONTEND_URL should be your Vercel URL (e.g. https://your-app.vercel.app)\n";
}
if ($dsn === "" || str_starts_with($dsn, "null://")) {
  echo "WARN: MAILER_DSN is not set — Brevo email will not work.\n";
}
if ($gid === "") {
  echo "WARN: GOOGLE_CLIENT_ID is not set — Sign in with Google will not work.\n";
}
$uri = getenv("DEFAULT_URI") ?: "";
if ($uri === "") {
  echo "WARN: DEFAULT_URI should be your Railway public URL (e.g. https://xxx.up.railway.app)\n";
}
$fp = getenv("FIREBASE_PROJECT_ID") ?: "";
$fsa = getenv("FIREBASE_SERVICE_ACCOUNT_JSON") ?: "";
if ($fp === "" || $fsa === "") {
  echo "WARN: FIREBASE_PROJECT_ID / FIREBASE_SERVICE_ACCOUNT_JSON missing — Android system push will not send.\n";
} else {
  echo "OK: Firebase push env present (project id set).\n";
}
$ws = getenv("WORKERMAN_INTERNAL_URL") ?: "";
if ($ws === "" || str_contains($ws, "localhost")) {
  echo "WARN: WORKERMAN_INTERNAL_URL should point to your Railway WebSocket internal HTTP endpoint for realtime.\n";
}
' 2>/dev/null || true

echo "Warming production cache..."
php bin/console cache:clear --env="${APP_ENV}" --no-warmup 2>/dev/null || true
php bin/console cache:warmup --env="${APP_ENV}" 2>/dev/null || true

PORT="${PORT:-8000}"
echo "Starting PHP on 0.0.0.0:${PORT} (router: public/index.php)..."
# Built-in server must use index.php as router or /api/* returns 404.
exec php -S "0.0.0.0:${PORT}" -t public public/index.php
