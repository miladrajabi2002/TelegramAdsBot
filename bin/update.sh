#!/usr/bin/env bash
#
# Re-deploy after a git pull or code change.
# Pulls, rebuilds assets, runs migrations, refreshes caches and restarts pm2.
#
#   sudo bash bin/update.sh
set -euo pipefail
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"
PHP_BIN="${PHP_BIN:-$(command -v php)}"

git pull --ff-only || true
composer install --no-interaction --prefer-dist --no-dev=false 2>/dev/null || composer install --no-interaction --prefer-dist
npm ci --no-audit --no-fund
npm run build
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
WEB_USER="${WEB_USER:-www-data}"
chown -R "$WEB_USER":"$WEB_USER" storage bootstrap/cache 2>/dev/null || true
chmod -R ug+rwX storage bootstrap/cache
pm2 restart ecosystem.config.cjs --update
echo "✓ deployed. Tail logs: pm2 logs tgads-queue"
