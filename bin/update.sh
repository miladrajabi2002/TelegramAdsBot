#!/usr/bin/env bash
#
# Re-deploy after a `git pull` or code change.
#
#   sudo bash bin/update.sh
#
# Pulls, rebuilds assets, runs migrations, refreshes all Laravel caches,
# and restarts PM2 processes (queue worker + scheduler) + PHP-FPM + nginx.
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

# Tell Composer we know we're root — silences the harmless warning.
export COMPOSER_ALLOW_SUPERUSER="${COMPOSER_ALLOW_SUPERUSER:-1}"

step "1/5  git pull"
git pull --ff-only || warn "git pull failed — continuing with the current tree"

step "2/5  composer + frontend"
composer install --no-interaction --prefer-dist --no-dev=false 2>/dev/null || composer install --no-interaction --prefer-dist
if command -v npm >/dev/null 2>&1; then
  npm ci --no-audit --no-fund
  npm run build
else
  warn "npm not found — skipping frontend rebuild"
fi

step "3/5  migrations + cache clear"
# Verify DB connection BEFORE running migrations so a stale .env doesn't
# corrupt the schema. If the connection is broken, fall back to letting
# the user fix it manually — same troubleshooting as install.sh.
"$PHP_BIN" artisan config:clear >/dev/null 2>&1 || true
if ! "$PHP_BIN" artisan db:show --no-interaction >/dev/null 2>&1; then
  e "Laravel cannot connect to the database — skipping migrations."
  echo "    Most common cause: MariaDB user exists only for one host"
  echo "    (localhost XOR 127.0.0.1). Fix with:"
  echo "      sudo mysql"
  echo "      CREATE USER '<DB_USER>'@'127.0.0.1' IDENTIFIED BY '<DB_PASS>';"
  echo "      GRANT ALL ON \`<DB_NAME>\`.* TO '<DB_USER>'@'127.0.0.1'; FLUSH PRIVILEGES;"
  echo "    Then re-run: sudo bash bin/update.sh"
  exit 1
fi
"$PHP_BIN" artisan migrate --force

# Flush every Laravel cache so newly deployed Blade templates, config,
# routes, and event/listener bindings are picked up. We clear BEFORE
# re-caching so a failed cache rebuild never leaves stale state behind.
ok "clearing view / config / route / app caches"
"$PHP_BIN" artisan view:clear
"$PHP_BIN" artisan config:clear
"$PHP_BIN" artisan route:clear
"$PHP_BIN" artisan cache:clear
"$PHP_BIN" artisan event:clear 2>/dev/null || true

# Rebuild the optimized caches now that the source tree is fresh.
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan storage:link 2>/dev/null || true

step "4/5  permissions"
mkdir -p "$PROJECT_DIR/storage/app/public" "$PROJECT_DIR/storage/logs" "$PROJECT_DIR/bootstrap/cache"
chown -R "$WEB_USER":"$WEB_USER" "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache" 2>/dev/null || true
chmod -R ug+rwX "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache"

step "5/5  reload services"
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

# Restart PM2 processes (queue worker + scheduler). We use --update so any
# changes to ecosystem.config.cjs (env vars, script paths) are picked up.
# If pm2 isn't installed we just warn — the operator can run install.sh
# once to set it up.
if command -v pm2 >/dev/null 2>&1; then
  if [[ -f "$PROJECT_DIR/ecosystem.config.cjs" ]]; then
    pm2 startOrReload "$PROJECT_DIR/ecosystem.config.cjs" --update 2>/dev/null \
      && ok "pm2 processes reloaded (startOrReload)" \
      || { pm2 restart ecosystem.config.cjs --update 2>/dev/null \
           && ok "pm2 processes restarted" \
           || warn "pm2 restart failed — run: pm2 logs" ; }
  else
    pm2 restart all --update 2>/dev/null \
      && ok "pm2 processes restarted" \
      || warn "pm2 restart failed — run: pm2 logs"
  fi
  # Always save the PM2 process list so it survives a server reboot.
  pm2 save 2>/dev/null || true
else
  warn "pm2 not installed — run bin/install.sh first"
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
