#!/usr/bin/env bash
#
# One-shot fix for: "Host '127.0.0.1' is not allowed to connect to this MariaDB server"
#
# This is the most common MariaDB pitfall: 'user'@'localhost' (socket) and
# 'user'@'127.0.0.1' (TCP) are DIFFERENT users in MariaDB. The install.sh
# here recreates the user with grants for both hosts plus '%' so you never
# hit this again.
#
# Usage:
#   sudo bash bin/fix-mariadb-auth.sh
#
# Or override the DB_USER/DB_NAME/DB_PASS if they're not in .env:
#   sudo DB_USER=tgadsbot DB_NAME=telegram_ads_bot DB_PASS='secret' \
#        bash bin/fix-mariadb-auth.sh
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$PROJECT_DIR/.env"

c()   { printf '\033[1;36m%s\033[0m\n' "$*"; }
e()   { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; }
ok()  { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }
warn(){ printf '\033[1;33m! %s\033[0m\n' "$*"; }

# ─────────────────────────────────────────────────────────────────────────────
# Read DB creds from .env (or env overrides)
# ─────────────────────────────────────────────────────────────────────────────
read_env() {
  local key="$1" default="${2:-}"
  local value
  if [[ -f "$ENV_FILE" ]]; then
    value="$(grep -E "^${key}=" "$ENV_FILE" | head -n1 | cut -d= -f2- | tr -d '"' || true)"
    echo "${value:-$default}"
  else
    echo "$default"
  fi
}

DB_USER="${DB_USER:-$(read_env DB_USERNAME tgadsbot)}"
DB_NAME="${DB_NAME:-$(read_env DB_DATABASE telegram_ads_bot)}"
DB_PASS="${DB_PASS:-$(read_env DB_PASSWORD)}"
DB_HOST="${DB_HOST:-$(read_env DB_HOST 127.0.0.1)}"
DB_PORT="${DB_PORT:-$(read_env DB_PORT 3306)}"

c "════════════════════════════════════════════════════════════"
c "  MariaDB Auth Fixer — Telegram Ads Bot"
c "════════════════════════════════════════════════════════════"
echo "  DB_USER   = $DB_USER"
echo "  DB_NAME   = $DB_NAME"
echo "  DB_HOST   = $DB_HOST"
echo "  DB_PASS   = ${DB_PASS:+<set, hidden>}"
echo

if [[ -z "$DB_PASS" ]]; then
  e "DB_PASSWORD is empty. Set it in .env or pass via env: sudo DB_PASS='...' bash bin/fix-mariadb-auth.sh"
  exit 1
fi

# ─────────────────────────────────────────────────────────────────────────────
# Try to connect as root via unix_socket (default on fresh MariaDB installs)
# ─────────────────────────────────────────────────────────────────────────────
MYSQL_CMD="mysql"
if ! $MYSQL_CMD -e "SELECT 1;" >/dev/null 2>&1; then
  if sudo -n $MYSQL_CMD -e "SELECT 1;" >/dev/null 2>&1; then
    MYSQL_CMD="sudo $MYSQL_CMD"
  else
    e "Couldn't connect to MariaDB as root via socket."
    echo
    echo "  Run this manually first to set a root password or socket auth:"
    echo "    sudo mysql_secure_installation"
    echo
    echo "  OR use sudo with a root password:"
    echo "    sudo MYSQL_ROOT_PASSWORD='...' bash bin/fix-mariadb-auth.sh"
    exit 1
  fi
fi
ok "Connected as root via socket"

# ─────────────────────────────────────────────────────────────────────────────
# Create database + user with grants for ALL three host patterns
# ─────────────────────────────────────────────────────────────────────────────
c "Creating database '$DB_NAME' (if not exists)…"
$MYSQL_CMD <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL
ok "Database ready"

c "Recreating user '$DB_USER' with grants on {localhost, 127.0.0.1, %}…"
# Use the password verbatim (escaped via single-quote wrapping; if the
# password itself contains a single-quote, the user should change it first).
$MYSQL_CMD <<SQL
-- Drop existing grants/users for all three host patterns.
DROP USER IF EXISTS '$DB_USER'@'localhost';
DROP USER IF EXISTS '$DB_USER'@'127.0.0.1';
DROP USER IF EXISTS '$DB_USER'@'%';

-- Recreate with the .env password.
CREATE USER '$DB_USER'@'localhost'   IDENTIFIED BY '$DB_PASS';
CREATE USER '$DB_USER'@'127.0.0.1'   IDENTIFIED BY '$DB_PASS';
CREATE USER '$DB_USER'@'%'           IDENTIFIED BY '$DB_PASS';

-- Grant on the database from every host.
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'%';
FLUSH PRIVILEGES;
SQL
ok "User '$DB_USER' recreated with grants on {localhost, 127.0.0.1, %}"

# ─────────────────────────────────────────────────────────────────────────────
# Sanity-test the new credentials from TCP (the original failure mode).
# ─────────────────────────────────────────────────────────────────────────────
c "Verifying TCP connection as '$DB_USER'@'127.0.0.1'…"
if mysql -u"$DB_USER" -p"$DB_PASS" -h"127.0.0.1" -P"$DB_PORT" -e "USE \`$DB_NAME\`; SELECT 1;" >/dev/null 2>&1; then
  ok "TCP connection as '$DB_USER'@'127.0.0.1' works."
else
  e "TCP connection still fails. Possible causes:"
  echo "  - bind-address in /etc/mysql/mariadb.conf.d/50-server.cnf is set to 127.0.0.1 but skip-networking=1 is on."
  echo "  - MariaDB is listening on a different port."
  echo "  - There's a leftover anonymous/ghost user. Run: sudo mysql -e \"SELECT User, Host FROM mysql.user WHERE User='$DB_USER';\""
  exit 1
fi

c "Verifying socket connection as '$DB_USER'@'localhost'…"
if mysql -u"$DB_USER" -p"$DB_PASS" -e "USE \`$DB_NAME\`; SELECT 1;" >/dev/null 2>&1; then
  ok "Socket connection as '$DB_USER'@'localhost' works."
else
  warn "Socket connection failed — but TCP works. That's OK if Laravel uses TCP (DB_HOST=127.0.0.1)."
fi

# ─────────────────────────────────────────────────────────────────────────────
# Final: confirm Laravel can talk to MariaDB.
# ─────────────────────────────────────────────────────────────────────────────
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
if [[ -n "$PHP_BIN" ]] && [[ -f "$PROJECT_DIR/artisan" ]]; then
  c "Verifying Laravel can talk to MariaDB…"
  cd "$PROJECT_DIR"
  "$PHP_BIN" artisan config:clear >/dev/null 2>&1 || true
  if "$PHP_BIN" artisan db:show --no-interaction >/dev/null 2>&1; then
    ok "Laravel DB connection verified ✅"
  else
    e "Laravel still can't connect. Check:"
    echo "  - .env has DB_USERNAME=$DB_USER  DB_DATABASE=$DB_NAME  DB_HOST=$DB_HOST"
    echo "  - DB_PASSWORD matches what was just set"
    echo "  - run: php artisan config:clear && php artisan db:show"
    exit 1
  fi
fi

echo
c "════════════════════════════════════════════════════════════"
ok "Done! Re-run install/update now:"
echo
echo "    sudo APP_DOMAIN=$(grep -E '^APP_URL=' "$ENV_FILE" | head -n1 | sed -E 's#^APP_URL=https?://([^/]+)/?.*$#\1#') bash bin/install.sh"
echo "    # or:"
echo "    sudo bash bin/update.sh"
c "════════════════════════════════════════════════════════════"
