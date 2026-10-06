#!/bin/bash
set -Eeuo pipefail

if [ "${EUID:-$(id -u)}" -ne 0 ]; then
    echo "This installer must run as root." >&2
    exit 1
fi

BOT_DIR=$(readlink -f -- "${1:-/var/www/html/mirzaprobotconfig}")
DB_NAME="${2:-VpnBot}"
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

[ -f "$SOURCE_DIR/server.js" ] && [ -f "$SOURCE_DIR/package.json" ] || {
    echo "Fragment signer source is missing from $SOURCE_DIR" >&2
    exit 2
}

export DEBIAN_FRONTEND=noninteractive
if ! command -v node >/dev/null 2>&1 || ! command -v npm >/dev/null 2>&1 \
    || ! command -v sqlite3 >/dev/null 2>&1 \
    || ! php8.2 -m 2>/dev/null | grep -qi '^pdo_sqlite$'; then
    apt-get update -o DPkg::Lock::Timeout=180 >/dev/null
    apt-get install -y -o DPkg::Lock::Timeout=180 nodejs npm php8.2-sqlite3 php8.2-curl sqlite3 ca-certificates >/dev/null
fi
node_major=$(node -p "Number(process.versions.node.split('.')[0])" 2>/dev/null || echo 0)
if [ "$node_major" -lt 18 ]; then
    echo "Node.js 18 or newer is required (installed: $(node --version 2>/dev/null || echo unknown))." >&2
    exit 3
fi

getent group www-data >/dev/null || groupadd --system www-data
id -u mirza-fragment >/dev/null 2>&1 || useradd --system --home-dir /nonexistent --shell /usr/sbin/nologin --gid www-data mirza-fragment
install -d -m 0750 -o root -g www-data "$ENV_DIR"
install -d -m 0750 -o mirza-fragment -g www-data "$STATE_DIR"
install -d -m 0750 -o www-data -g www-data "$PHP_DATA_DIR" "$PHP_DATA_DIR/$SAFE_DB"
install -d -m 0755 -o root -g root "$INSTALL_DIR"
rsync -a --delete --exclude='node_modules/' "$SOURCE_DIR/" "$INSTALL_DIR/"
cd "$INSTALL_DIR"
npm install --omit=dev --no-audit --no-fund >/dev/null
chown -R root:root "$INSTALL_DIR"

TOKEN=''
PORT=''
if [ -r "$ENV_FILE" ]; then
    TOKEN=$(sed -n 's/^SIGNER_TOKEN=//p' "$ENV_FILE" | head -1 | tr -d '\r\n')
    PORT=$(sed -n 's/^PORT=//p' "$ENV_FILE" | head -1 | tr -cd '0-9')
fi
[ ${#TOKEN} -ge 32 ] || TOKEN=$(openssl rand -hex 32)
if ! [[ "$PORT" =~ ^[0-9]+$ ]] || [ "$PORT" -lt 1024 ] || [ "$PORT" -gt 65535 ]; then
    PORT=8787
    while ss -ltnH "sport = :$PORT" 2>/dev/null | grep -q .; do
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
chown root:www-data "$ENV_FILE"
chmod 0640 "$ENV_FILE"

cat > "/etc/systemd/system/$SERVICE" <<EOF
[Unit]
Description=Mirza Fragment TON signer ($SAFE_DB)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=mirza-fragment
Group=www-data
WorkingDirectory=$INSTALL_DIR
EnvironmentFile=$ENV_FILE
ExecStart=/usr/bin/node $INSTALL_DIR/server.js
Restart=on-failure
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
* * * * * www-data /usr/bin/flock -n /run/lock/mirza-fragment-$SLUG.lock /usr/bin/php8.2 $BOT_DIR/cronbot/fragment_orders.php >/dev/null 2>&1
EOF
chmod 0644 "$CRON_FILE"

systemctl daemon-reload
systemctl enable --now "$SERVICE" >/dev/null
systemctl restart "$SERVICE"
systemctl is-active --quiet "$SERVICE"
systemctl reload cron >/dev/null 2>&1 || systemctl restart cron >/dev/null 2>&1 || true

echo "Fragment signer ready on 127.0.0.1:$PORT"

