#!/usr/bin/env bash
#
# Pool Queue — deploy / update script for cPanel (or any shared host with SSH).
#
# First deploy: clone the repo, create .env, run `php artisan key:generate` and
# `php artisan migrate --force`, then run this script.
#
# Every update after that:  cd ~/pool-queue && ./deploy.sh
#
# Override the PHP/Composer binaries if the host's defaults are too old, e.g.:
#   PHP_BIN=/opt/cpanel/ea-php83/root/usr/bin/php ./deploy.sh
#   COMPOSER_BIN="$PHP_BIN composer.phar" ./deploy.sh

set -euo pipefail

cd "$(dirname "$0")"

PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
GIT_PULL="${GIT_PULL:-1}"        # set GIT_PULL=0 to skip the pull
RUN_MIGRATIONS="${RUN_MIGRATIONS:-1}"

echo "==> PHP:      $($PHP_BIN -r 'echo PHP_VERSION;')"
echo "==> Dir:      $(pwd)"

if [ ! -f .env ]; then
  echo "!! No .env found. Copy .env.example to .env and configure it first." >&2
  exit 1
fi

if [ "$GIT_PULL" = "1" ] && [ -d .git ]; then
  echo "==> git pull"
  git pull --ff-only
fi

echo "==> composer install (no-dev)"
$COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction --prefer-dist

if [ "$RUN_MIGRATIONS" = "1" ]; then
  echo "==> php artisan migrate --force"
  $PHP_BIN artisan migrate --force
fi

echo "==> Rebuilding caches"
$PHP_BIN artisan config:clear
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:cache

echo "==> Ensuring writable dirs"
chmod -R 775 storage bootstrap/cache || true

echo "==> Done."
