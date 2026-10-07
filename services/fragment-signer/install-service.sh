#!/bin/bash
# نصب و تعمیر یک‌مرحله‌ای سرویس امضای Fragment (استارز و پریمیوم خودکار)
#
# فقط همین یک دستور کافی است (هیچ آرگومانی لازم نیست؛ مسیر ربات و نام دیتابیس خودکار پیدا می‌شوند):
#     sudo bash services/fragment-signer/install-service.sh
# اجرای دوباره‌ی آن امن است و هر مشکل رایج (Node قدیمی، ماژول sqlite برای PHP، سرویس خاموش، دسترسی فایل) را تعمیر می‌کند.
#
# آرگومان‌های اختیاری: install-service.sh [مسیر_ربات] [نام_دیتابیس]
set -Eeuo pipefail

if [ "${EUID:-$(id -u)}" -ne 0 ]; then
    if command -v sudo >/dev/null 2>&1; then
        exec sudo -E bash "$0" "$@"
    fi
    echo "This installer must run as root." >&2
    exit 1
fi

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
DEFAULT_BOT_DIR=$(readlink -f -- "$SCRIPT_DIR/../..")
BOT_DIR=$(readlink -f -- "${1:-$DEFAULT_BOT_DIR}")

# نام دیتابیس: آرگومان دوم، وگرنه از config.php ربات (همان نامی که ربات برای پیدا کردن فایل env استفاده می‌کند)
DB_NAME="${2:-}"
if [ -z "$DB_NAME" ] && [ -r "$BOT_DIR/config.php" ]; then
    DB_NAME=$(sed -nE 's/^[[:space:]]*\$dbname[[:space:]]*=[[:space:]]*["'"'"']([^"'"'"']+)["'"'"'].*/\1/p' "$BOT_DIR/config.php" | head -1 | tr -d '\r')
    if [ -z "$DB_NAME" ] && command -v php >/dev/null 2>&1; then
        DB_NAME=$(php -r 'require $argv[1]; echo (string) $dbname;' "$BOT_DIR/config.php" 2>/dev/null || true)
    fi
fi
[ -n "$DB_NAME" ] || DB_NAME=VpnBot
SAFE_DB=$(printf '%s' "$DB_NAME" | tr -cd 'A-Za-z0-9_.-')
[ -n "$SAFE_DB" ] || SAFE_DB=default
SLUG=$(printf '%s' "$SAFE_DB" | tr '[:upper:]_.:' '[:lower:]---' | tr -cd 'a-z0-9-')
[ -n "$SLUG" ] || SLUG=default

SOURCE_DIR="$BOT_DIR/services/fragment-signer"
INSTALL_DIR="/opt/mirza-fragment-signer-$SLUG"
STATE_DIR="/var/lib/mirza-fragment/$SLUG/signer"
PHP_DATA_DIR="/var/lib/mirza-fragment/php-data"
ENV_DIR="/etc/mirza"
ENV_FILE="$ENV_DIR/fragment-signer-$SAFE_DB.env"
SERVICE="mirza-fragment-signer-$SLUG.service"
CRON_FILE="/etc/cron.d/mirza-fragment-$SLUG"

say() { echo "[fragment] $*"; }

[ -f "$SOURCE_DIR/server.js" ] && [ -f "$SOURCE_DIR/package.json" ] || {
    echo "Fragment signer source is missing from $SOURCE_DIR" >&2
    exit 2
}
say "ربات: $BOT_DIR | دیتابیس: $SAFE_DB"

export DEBIAN_FRONTEND=noninteractive
APT_OPTS=(-o DPkg::Lock::Timeout=180 -y -qq)
APT_UPDATED=0
apt_install() {
    if [ "$APT_UPDATED" -eq 0 ]; then
        apt-get update -o DPkg::Lock::Timeout=180 >/dev/null || true
        APT_UPDATED=1
    fi
    apt-get install "${APT_OPTS[@]}" "$@" >/dev/null
}

# ابزارهای پایه
for tool in curl rsync openssl sqlite3 gpg; do
    command -v "$tool" >/dev/null 2>&1 && continue
    case "$tool" in
        gpg) apt_install gnupg ca-certificates ;;
        *) apt_install "$tool" ca-certificates ;;
    esac
done

# Node.js 18 یا بالاتر (در صورت نبودن یا قدیمی بودن، نسخه‌ی 20 از NodeSource نصب می‌شود)
node_major() { node -p "Number(process.versions.node.split('.')[0])" 2>/dev/null || echo 0; }
if [ "$(node_major)" -lt 18 ] || ! command -v npm >/dev/null 2>&1; then
    say "نصب Node.js 20 ..."
    apt_install ca-certificates curl gnupg
    install -m 0755 -d /etc/apt/keyrings
    rm -f /etc/apt/keyrings/nodesource.gpg
    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg
    chmod 0644 /etc/apt/keyrings/nodesource.gpg
    echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_20.x nodistro main" > /etc/apt/sources.list.d/nodesource.list
    APT_UPDATED=0
    apt_install nodejs
fi
if [ "$(node_major)" -lt 18 ] || ! command -v npm >/dev/null 2>&1; then
    echo "Node.js 18 or newer is required (installed: $(node --version 2>/dev/null || echo unknown))." >&2
    exit 3
fi
NODE_BIN=$(command -v node)

# PHP: ماژول‌های sqlite و curl برای «همه‌ی نسخه‌های نصب‌شده‌ی PHP» (نسخه‌ی وب ممکن است با CLI فرق کند)
PHP_VERSIONS=()
if [ -d /etc/php ]; then
    for d in /etc/php/*/; do
        v=$(basename "$d")
        [[ "$v" =~ ^[0-9]+\.[0-9]+$ ]] && [ -d "/etc/php/$v/mods-available" ] && PHP_VERSIONS+=("$v")
    done
fi
if [ ${#PHP_VERSIONS[@]} -eq 0 ] && command -v php >/dev/null 2>&1; then
    PHP_VERSIONS+=("$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')")
fi
NEED_PHP_RESTART=0
for v in "${PHP_VERSIONS[@]}"; do
    pkgs=()
    dpkg -s "php$v-sqlite3" >/dev/null 2>&1 || pkgs+=("php$v-sqlite3")
    dpkg -s "php$v-curl" >/dev/null 2>&1 || pkgs+=("php$v-curl")
    if [ ${#pkgs[@]} -gt 0 ]; then
        say "نصب ${pkgs[*]} ..."
        apt_install "${pkgs[@]}" || say "هشدار: نصب ${pkgs[*]} ناموفق بود"
        NEED_PHP_RESTART=1
    fi
done
# پوشه‌ی داده‌ی SQLite باید توسط PHP قابل نوشتن باشد؛ ماژول‌ها باید فعال باشند
if command -v phpenmod >/dev/null 2>&1; then
    phpenmod pdo_sqlite sqlite3 curl >/dev/null 2>&1 || true
fi
if [ "$NEED_PHP_RESTART" -eq 1 ]; then
    systemctl restart apache2 >/dev/null 2>&1 || true
    for v in "${PHP_VERSIONS[@]}"; do
        systemctl restart "php$v-fpm" >/dev/null 2>&1 || true
    done
    systemctl reload nginx >/dev/null 2>&1 || true
fi

# PHP مخصوص cron: همان نسخه‌ای که ربات با آن کار می‌کند (اولویت 8.2)
PHP_BIN=""
for cand in /usr/bin/php8.2 /usr/bin/php8.3 /usr/bin/php8.1 /usr/bin/php8.4 /usr/bin/php; do
    [ -x "$cand" ] && { PHP_BIN="$cand"; break; }
done
[ -n "$PHP_BIN" ] || { echo "PHP CLI not found." >&2; exit 5; }
PHP_MODULES=$("$PHP_BIN" -m 2>/dev/null || true)
grep -qi '^pdo_sqlite$' <<<"$PHP_MODULES" || { echo "pdo_sqlite is not loaded in $PHP_BIN" >&2; exit 6; }

# کاربر و پوشه‌ها (گروه PHP وب به‌صورت خودکار تشخیص داده می‌شود)
WEB_GROUP=www-data
getent group "$WEB_GROUP" >/dev/null || groupadd --system "$WEB_GROUP"
id -u mirza-fragment >/dev/null 2>&1 || useradd --system --home-dir /nonexistent --shell /usr/sbin/nologin --gid "$WEB_GROUP" mirza-fragment
install -d -m 0750 -o root -g "$WEB_GROUP" "$ENV_DIR"
install -d -m 0750 -o mirza-fragment -g "$WEB_GROUP" "$STATE_DIR"
install -d -m 0750 -o www-data -g "$WEB_GROUP" "$PHP_DATA_DIR" "$PHP_DATA_DIR/$SAFE_DB"
install -d -m 0755 -o root -g root "$INSTALL_DIR"
rsync -a --delete --exclude='node_modules/' "$SOURCE_DIR/" "$INSTALL_DIR/"
cd "$INSTALL_DIR"
say "نصب وابستگی‌های Node ..."
npm install --omit=dev --no-audit --no-fund >/dev/null
chown -R root:root "$INSTALL_DIR"

# توکن و پورت (در اجرای دوباره، همان مقدارهای قبلی حفظ می‌شوند)
TOKEN=''
PORT=''
if [ -r "$ENV_FILE" ]; then
    TOKEN=$(sed -n 's/^SIGNER_TOKEN=//p' "$ENV_FILE" | head -1 | tr -d '\r\n')
    PORT=$(sed -n 's/^PORT=//p' "$ENV_FILE" | head -1 | tr -cd '0-9')
fi
[ ${#TOKEN} -ge 32 ] || TOKEN=$(openssl rand -hex 32)
OWN_PID=$(systemctl show -p MainPID --value "$SERVICE" 2>/dev/null || echo 0)
port_busy_by_other() {
    local line
    line=$(ss -ltnpH "sport = :$1" 2>/dev/null || true)
    [ -n "$line" ] || return 1
    [ "${OWN_PID:-0}" != "0" ] && printf '%s' "$line" | grep -q "pid=$OWN_PID," && return 1
    return 0
}
if ! [[ "$PORT" =~ ^[0-9]+$ ]] || [ "$PORT" -lt 1024 ] || [ "$PORT" -gt 65535 ] || port_busy_by_other "$PORT"; then
    PORT=8787
    while port_busy_by_other "$PORT"; do
        PORT=$((PORT + 1))
        [ "$PORT" -le 8899 ] || { echo "No free local signer port found." >&2; exit 4; }
    done
fi

umask 027
cat > "$ENV_FILE" <<EOF
SIGNER_TOKEN=$TOKEN
HOST=127.0.0.1
PORT=$PORT
STATE_FILE=$STATE_DIR/state.json
CONFIG_FILE=$STATE_DIR/signer-config.json
TOKEN_FILE=$STATE_DIR/signer-token.txt
MIRZA_FRAGMENT_SIGNER_URL=http://127.0.0.1:$PORT
MIRZA_FRAGMENT_DATA_DIR=$PHP_DATA_DIR
EOF
chown root:"$WEB_GROUP" "$ENV_FILE"
chmod 0640 "$ENV_FILE"

cat > "/etc/systemd/system/$SERVICE" <<EOF
[Unit]
Description=Mirza Fragment TON signer ($SAFE_DB)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=mirza-fragment
Group=$WEB_GROUP
WorkingDirectory=$INSTALL_DIR
EnvironmentFile=$ENV_FILE
ExecStart=$NODE_BIN $INSTALL_DIR/server.js
Restart=always
RestartSec=5
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=$STATE_DIR

[Install]
WantedBy=multi-user.target
EOF

cat > "$CRON_FILE" <<EOF
* * * * * www-data /usr/bin/flock -n /run/lock/mirza-fragment-$SLUG.lock $PHP_BIN $BOT_DIR/cronbot/fragment_orders.php >/dev/null 2>&1
EOF
chmod 0644 "$CRON_FILE"

systemctl daemon-reload
systemctl enable "$SERVICE" >/dev/null 2>&1
systemctl restart "$SERVICE"

# بررسی سلامت (تا ۳۰ ثانیه صبر می‌کند)
ok=0
for _ in $(seq 1 30); do
    if curl -fsS --max-time 2 "http://127.0.0.1:$PORT/health" >/dev/null 2>&1; then ok=1; break; fi
    sleep 1
done
if [ "$ok" -ne 1 ]; then
    echo "Fragment signer did not become healthy. Last logs:" >&2
    journalctl -u "$SERVICE" -n 20 --no-pager >&2 || true
    exit 7
fi
systemctl reload cron >/dev/null 2>&1 || systemctl restart cron >/dev/null 2>&1 || true

# کاربر وب باید بتواند فایل env را بخواند (در غیر این صورت ربات «سرویس امضا قطع» نشان می‌دهد)
if id -u www-data >/dev/null 2>&1 && ! runuser -u www-data -- test -r "$ENV_FILE" 2>/dev/null; then
    say "هشدار: کاربر www-data به $ENV_FILE دسترسی ندارد."
fi

say "سرویس امضا آماده است."
echo "Fragment signer ready on 127.0.0.1:$PORT"
