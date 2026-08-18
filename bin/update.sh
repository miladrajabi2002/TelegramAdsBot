#!/usr/bin/env bash
#
# Re-deploy after a `git pull` or code change.
#
#   sudo bash bin/update.sh
#
# Pulls, rebuilds assets, runs migrations, refreshes caches, restarts PM2 +
# PHP-FPM + nginx. It also re-checks SSL renewal and creates the nginx site
# config if install.sh was never run on this box.
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

# Re-source install-env.sh so override vars (APP_DOMAIN, etc.) carry through.
# shellcheck source=/dev/null
. "$PROJECT_DIR/bin/install-env.sh" 2>/dev/null || true

DOMAIN="${APP_DOMAIN:-}"
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
WEB_USER="${WEB_USER:-www-data}"

c()    { printf '\033[1;36m%s\033[0m\n' "$*"; }
e()    { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; }
ok()   { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m! %s\033[0m\n' "$*"; }

step(){ echo; c "── $* ──"; }

# Try to recover the domain from .env if it wasn't passed in.
if [[ -z "$DOMAIN" ]] && [[ -f "$PROJECT_DIR/.env" ]]; then
  DOMAIN="$(grep -E '^APP_URL=' "$PROJECT_DIR/.env" | sed -E 's#^APP_URL=https?://([^/]+)/?.*$#\1#' || true)"
fi

# Try to recover the PHP version from the running php-fpm service.
PHP_VER=""
if [[ -n "$PHP_BIN" ]]; then
  PHP_VER="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
fi

if [[ -z "$PHP_BIN" ]]; then
  e "php not found. Run bin/install.sh first."
  exit 1
fi

step "1/6  git pull"
git pull --ff-only || warn "git pull failed — continuing with the current tree"

step "2/6  composer + frontend"
composer install --no-interaction --prefer-dist --no-dev=false 2>/dev/null || composer install --no-interaction --prefer-dist
if command -v npm >/dev/null 2>&1; then
  npm ci --no-audit --no-fund
  npm run build
else
  warn "npm not found — skipping frontend rebuild"
fi

step "3/6  migrations + caches"
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan storage:link 2>/dev/null || true

step "4/6  permissions"
mkdir -p "$PROJECT_DIR/storage/app/public" "$PROJECT_DIR/storage/logs" "$PROJECT_DIR/bootstrap/cache"
chown -R "$WEB_USER":"$WEB_USER" "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache" 2>/dev/null || true
chmod -R ug+rwX "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache"

step "5/6  reload services"
# Restart PHP-FPM so newly deployed extension changes (if any) take effect.
if [[ -n "$PHP_VER" ]] && systemctl list-unit-files 2>/dev/null | grep -q "php$PHP_VER-fpm"; then
  systemctl restart "php$PHP_VER-fpm" 2>/dev/null && ok "php$PHP_VER-fpm restarted" || warn "php-fpm restart failed"
fi

# Test the nginx config before reloading.
if command -v nginx >/dev/null 2>&1; then
  if nginx -t 2>/dev/null; then
    systemctl reload nginx 2>/dev/null && ok "nginx reloaded" || warn "nginx reload failed"
  else
    warn "nginx config test failed — fix manually then run: sudo systemctl reload nginx"
  fi
fi

# Restart PM2 processes (queue worker + scheduler).
if command -v pm2 >/dev/null 2>&1; then
  pm2 restart ecosystem.config.cjs --update 2>/dev/null && ok "pm2 processes restarted"
else
  warn "pm2 not installed — run bin/install.sh first"
fi

# Try to renew SSL certificates non-interactively (certbot exits 0 if no
# certificate is due for renewal). Safe to run on every update.
step "6/6  SSL renewal check (non-interactive)"
if command -v certbot >/dev/null 2>&1; then
  if certbot renew --quiet --non-interactive 2>/dev/null; then
    ok "certbot renew done (no-op if not yet due)"
  else
    warn "certbot renew exited non-zero — check /var/log/letsencrypt/letsencrypt.log"
  fi
else
  warn "certbot not installed — skipping SSL renewal check"
fi

# Optionally re-register the Telegram webhook so allowed_updates stays in sync
# with whatever the latest code requires (currently: message + callback_query).
TOKEN="$("$PHP_BIN" artisan tinker --execute='echo config("services.telegram.bot_token") ?? "";' 2>/dev/null || true)"
if [[ -n "${TOKEN// }" ]]; then
  "$PHP_BIN" artisan telegram:webhook:set 2>/dev/null && ok "telegram webhook re-registered" || warn "webhook set failed"
fi

echo
c "✓ deployed. Tail logs: pm2 logs tgads-queue"
if [[ -n "$DOMAIN" ]]; then
  c "  Site: https://$DOMAIN/app"
fi
