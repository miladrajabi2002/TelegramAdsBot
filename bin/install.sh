#!/usr/bin/env bash
#
# One-click install / deploy for the Telegram Ads Bot on a Linux server.
#
#   sudo bash bin/install.sh
#
# Idempotent: safe to re-run. It will:
#   1. Check / install PHP 8.3+ extensions required by Laravel.
#   2. Disable the php-psr extension (conflicts with userland psr/log).
#   3. composer install + npm ci + npm run build.
#   4. Create a .env with generated secrets when missing (and a MySQL db+user
#      when MariaDB/MySQL is reachable on the host).
#   5. migrate --seed, storage:link, caches, permissions.
#   6. Install pm2 if missing, register startup, start the bot processes and
#      persist them (pm2 save).
#   7. (Optional) register the Telegram webhook if TELEGRAM_BOT_TOKEN is set.
#
# Override any of these via environment before running, e.g.:
#   sudo APP_DOMAIN=bot.example.com DB_NAME=x DB_USER=y DB_PASS=z bash bin/install.sh
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

# shellcheck source=/dev/null
. "$PROJECT_DIR/bin/install-env.sh" 2>/dev/null || true

DOMAIN="${APP_DOMAIN:-bot.miladrajabi.com}"
APP_URL="${APP_URL:-https://$DOMAIN}"
DB_NAME="${DB_NAME:-telegram_ads_bot}"
DB_USER="${DB_USER:-tgadsbot}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"

c() { printf '\033[1;36m%s\033[0m\n' "$*"; }
e() { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; }
ok() { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }

step() { echo; c "── $* ──"; }

step "1/8  PHP runtime & extensions"
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
if [[ -z "$PHP_BIN" ]]; then
  e "php not found. Install PHP 8.3+ first, then re-run."
  exit 1
fi
PHP_VER="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
ok "PHP $PHP_VER at $PHP_BIN"

ensure_php_ext() {
  local pkg="$1" mod="$2"
  if ! "$PHP_BIN" -m 2>/dev/null | grep -iq "^$mod$"; then
    if [[ -f /etc/debian_version ]] || command -v apt-get >/dev/null 2>&1; then
      apt-get update -qq
      apt-get install -y "$pkg" >/dev/null && ok "installed $pkg"
    fi
    # Create ini stub when the .so exists but no mods-available entry (happens
    # on some minimal builds where the shared extension is shipped unlinked).
    local so="/usr/lib/php/$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.PHP_MINOR_VERSION;')/${mod}.so"
    local ini="/etc/php/$PHP_VER/mods-available/${mod}.ini"
    if ! "$PHP_BIN" -m 2>/dev/null | grep -iq "^$mod$" && [[ -f "$so" && ! -f "$ini" ]]; then
      printf "; configuration for %s module\n; priority=20\nextension=%s.so\n" "$mod" "$mod" > "$ini"
      phpenmod -v "$PHP_VER" "$mod" >/dev/null 2>&1 || true
    fi
  fi
  "$PHP_BIN" -m 2>/dev/null | grep -iq "^$mod$" && ok "ext $mod" || e "ext $mod missing (install $pkg)"
}

for spec in \
  "php8.4-common ctype" \
  "php8.4-curl curl" \
  "php8.4-dom dom" \
  "php8.4-mbstring mbstring" \
  "php8.4-openssl openssl" \
  "php8.4-pdo pdo" \
  "php8.4-mysql pdo_mysql" \
  "php8.4-tokenizer tokenizer" \
  "php8.4-xml xmlwriter" \
  "php8.4-intl intl" \
  "php8.4-fileinfo fileinfo" \
  "php8.4-sqlite3 sqlite3" \
  "php8.4-pdo_sqlite pdo_sqlite" \
  "php8.4-bcmath bcmath" \
  "php8.4-gd gd"; do
  ensure_php_ext $spec
done

# php-psr extension ships native PsrExt\ classes that conflict with the
# userland psr/log interfaces Monolog expects; disable it everywhere.
if "$PHP_BIN" -m 2>/dev/null | grep -iq "^psr$"; then
  phpdismod -v "$PHP_VER" psr >/dev/null 2>&1 || true
  rm -f "/etc/php/$PHP_VER/cli/conf.d/"*psr* "/etc/php/$PHP_VER/fpm/conf.d/"*psr*
  ok "disabled php-psr (conflicts with psr/log)"
fi

step "2/8  composer dependencies"
if ! command -v composer >/dev/null 2>&1; then
  c "installing composer…"
  EXPECTED_SIG="$(curl -fsSL https://composer.github.io/installer.sig)"
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  ACTUAL_SIG="$("$PHP_BIN" /tmp/composer-setup.php --check 2>/dev/null || true)"
  "$PHP_BIN" /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi
composer install --no-interaction --prefer-dist --no-dev=false 2>/dev/null || composer install --no-interaction --prefer-dist
ok "composer"

step "3/8  frontend build"
if ! command -v node >/dev/null 2>&1; then
  e "Node.js not found. Install Node 18+ (e.g. via nvm or NodeSource) then re-run."
  exit 1
fi
npm ci --no-audit --no-fund
npm run build
ok "frontend built"

step "4/8  environment (.env) and database"
if [[ ! -f "$PROJECT_DIR/.env" ]]; then
  DB_PASS="${DB_PASS:-$(openssl rand -hex 18)}"
  KYC_HMAC_KEY="${KYC_HMAC_KEY:-$(openssl rand -hex 32)}"
  TG_WEBHOOK_SECRET="${TELEGRAM_WEBHOOK_SECRET:-$(openssl rand -hex 24)}"
  ADMIN_PASSWORD="${ADMIN_PASSWORD:-$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)}"
  cp "$PROJECT_DIR/.env.example" "$PROJECT_DIR/.env"

  # Attempt local MySQL/MariaDB database + user creation (best-effort).
  if command -v mysql >/dev/null 2>&1 && mysql -e "SELECT 1;" >/dev/null 2>&1; then
    mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || true
    mysql -e "DROP USER IF EXISTS '$DB_USER'@'$DB_HOST'; CREATE USER '$DB_USER'@'$DB_HOST' IDENTIFIED BY '$DB_PASS'; GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'$DB_HOST'; CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS'; GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;" || true
    ok "database $DB_NAME + user $DB_USER"
  else
    c "MySQL/MariaDB root access not available; create $DB_NAME / $DB_USER manually."
  fi

  APP_KEY="$("$PHP_BIN" artisan key:generate --show)"
  ADMIN_EMAIL="${ADMIN_EMAIL:-admin@$DOMAIN}"
  tee "$PROJECT_DIR/.env" >/dev/null <<EOF
APP_NAME="Telegram Ads Bot"
APP_ENV=production
APP_KEY=$APP_KEY
APP_DEBUG=false
APP_URL=$APP_URL

APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_DATABASE=$DB_NAME
DB_USERNAME=$DB_USER
DB_PASSWORD=$DB_PASS

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=$DOMAIN

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=log

VITE_APP_NAME="\${APP_NAME}"

ADS_PLATFORM_BRAND="Telegram Ads Bot"
ADS_PLATFORM_CHANNEL_USERNAME=
ADS_PLATFORM_SUPPORT_USERNAME=
ADS_PLATFORM_MARKUP_BPS=1500
ADS_PLATFORM_MINIMUM_ORDER_IRR=1000000
ADS_PLATFORM_MIN_TARGET_MEMBERS=1000
ADS_PLATFORM_MAX_CHANNELS_PER_CATEGORY=30
ADS_PLATFORM_TIMEZONE=Asia/Tehran
ADS_PLATFORM_DEMO_MODE=false
KYC_RETENTION_DAYS=1825
KYC_HMAC_KEY=$KYC_HMAC_KEY
USD_TO_IRR=600000
GRAM_TO_USD=3.25

TELEGRAM_BOT_TOKEN=${TELEGRAM_BOT_TOKEN:-}
TELEGRAM_BOT_USERNAME=${TELEGRAM_BOT_USERNAME:-}
TELEGRAM_WEBHOOK_SECRET=$TG_WEBHOOK_SECRET
TELEGRAM_INIT_DATA_TTL=300

ZARINPAY_BASE_URL=https://zarinmee.ir/api
ZARINPAY_ACCESS_TOKEN=
ZARINPAY_ENABLED=false
ZARINPAY_MOCK=false
ZARINPAY_TIMEOUT=15
ZARINPAY_PAYMENT_HOSTS=zarinmee.ir

NOWPAYMENTS_BASE_URL=https://api.nowpayments.io/v1
NOWPAYMENTS_API_KEY=
NOWPAYMENTS_PUBLIC_KEY=
NOWPAYMENTS_IPN_SECRET=
NOWPAYMENTS_ENABLED=false
NOWPAYMENTS_INVOICE_HOSTS=nowpayments.io

ADMIN_NAME="Platform Owner"
ADMIN_EMAIL=$ADMIN_EMAIL
ADMIN_PASSWORD=$ADMIN_PASSWORD
EOF
  chmod 600 "$PROJECT_DIR/.env"
  ok ".env created"
  echo "  ADMIN_EMAIL = $ADMIN_EMAIL"
  echo "  ADMIN_PASSWORD = $ADMIN_PASSWORD   <-- keep this"
  echo "  TELEGRAM_WEBHOOK_SECRET = $TG_WEBHOOK_SECRET"
else
  ok ".env already exists (leaving untouched)"
fi

step "5/8  database schema, seed, caches"
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan db:seed --force
"$PHP_BIN" artisan storage:link 2>/dev/null || true
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
ok "schema + caches"

step "6/8  permissions"
mkdir -p "$PROJECT_DIR/storage/app/public" "$PROJECT_DIR/storage/logs" "$PROJECT_DIR/bootstrap/cache"
WEB_USER="${WEB_USER:-www-data}"
chown -R "$WEB_USER":"$WEB_USER" "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache" "$PROJECT_DIR/.env" 2>/dev/null || true
chmod -R ug+rwX "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache"
ok "permissions"

step "7/8  process manager (pm2)"
if ! command -v pm2 >/dev/null 2>&1; then
  c "pm2 not found, installing…"
  npm install -g pm2
fi
if ! command -v pm2 >/dev/null 2>&1; then
  e "pm2 still not on PATH. Install Node/npm first, then re-run."
  exit 1
fi
export PM2_HOME="${PM2_HOME:-/root/.pm2}"
pm2 start ecosystem.config.cjs --update 2>/dev/null || pm2 start ecosystem.config.cjs
pm2 save
if command -v systemctl >/dev/null 2>&1; then
  pm2 startup systemd -u root --hp "$PM2_HOME" 2>/dev/null | grep -q 'systemctl enable' && {
    pm2 startup systemd -u root --hp "$PM2_HOME" 2>/dev/null || true
  } || true
fi
ok "pm2 running + saved"

step "8/8  telegram webhook (optional)"
TOKEN="$("$PHP_BIN" artisan tinker --execute='echo config("services.telegram.bot_token") ?? "";')"
if [[ -n "${TOKEN// }" ]]; then
  "$PHP_BIN" artisan telegram:webhook:set && ok "webhook set"
else
  c "TELEGRAM_BOT_TOKEN not set — skipping webhook. Set it in .env and re-run."
fi

echo
c "══════════════════════════════════════════════════"
c "  Telegram Ads Bot installed on $DOMAIN"
c "  Admin panel : https://$DOMAIN/admin/login"
c "  Mini App    : https://$DOMAIN/app"
c "  Webhook URL : https://$DOMAIN/webhooks/telegram"
c "  Health      : https://$DOMAIN/healthz"
c "══════════════════════════════════════════════════"
echo
echo "Logs:        pm2 logs tgads-queue"
echo "Status:      pm2 status"
echo "Restart app: bash bin/update.sh"
echo "Full guide:  docs/SERVER_DEPLOYMENT.md"
