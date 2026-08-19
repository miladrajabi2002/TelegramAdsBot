#!/usr/bin/env bash
#
# Reload TelegramAdsBot after a code change — minimal version.
#
#   sudo bash bin/reload.sh
#
# Runs ONLY the commands the operator asked for:
#   1. npm run build
#   2. php artisan view:clear
#   3. php artisan config:clear
#   4. php artisan route:clear
#   5. php artisan cache:clear
#   6. pm2 restart 16   (tgads-queue)
#   7. pm2 restart 17   (tgads-sched)
#
# This script does NOT run migrations, NOT touch nginx/php-fpm, NOT pull git,
# NOT run composer. Use bin/update.sh for a full deploy.
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

c()    { printf '\033[1;36m%s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }
e()    { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; }
warn() { printf '\033[1;33m! %s\033[0m\n' "$*"; }

PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
if [[ -z "$PHP_BIN" ]]; then
  e "php not found. Run bin/install.sh first."
  exit 1
fi

step(){ echo; c "── $* ──"; }

step "1/7  npm run build"
if command -v npm >/dev/null 2>&1; then
  npm run build
  ok "frontend rebuilt"
else
  warn "npm not found — skipping frontend rebuild"
fi

step "2/7  php artisan view:clear"
"$PHP_BIN" artisan view:clear
ok "views cleared"

step "3/7  php artisan config:clear"
"$PHP_BIN" artisan config:clear
ok "config cleared"

step "4/7  php artisan route:clear"
"$PHP_BIN" artisan route:clear
ok "routes cleared"

step "5/7  php artisan cache:clear"
"$PHP_BIN" artisan cache:clear
ok "cache cleared"

step "6/7  pm2 restart 16 (tgads-queue)"
if command -v pm2 >/dev/null 2>&1; then
  pm2 restart 16
  ok "tgads-queue restarted"
else
  e "pm2 not installed"
  exit 1
fi

step "7/7  pm2 restart 17 (tgads-sched)"
pm2 restart 17
ok "tgads-sched restarted"

echo
c "✓ reload complete. Tail logs: pm2 logs tgads-queue"
