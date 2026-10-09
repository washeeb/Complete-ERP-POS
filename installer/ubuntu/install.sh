#!/usr/bin/env bash
# Synclytics ERP — one-command installation on a plain Ubuntu 24.04 server (setups B, C, D and branch servers),
# doing what Chapter 5 of the installation guide describes by hand.
#
#   sudo bash install.sh --zip ~/synclytics-<customer>-<build>.zip [--domain erp.example.com --email you@example.com]
#                        [--role hq|branch --node-id HQ] [--dir /var/www/synclytics]
#
# Safe to run again (an update): it keeps .env, the database and every key, and only adds what is missing.
set -euo pipefail

ZIP=""; SRC=""; DOMAIN=""; EMAIL=""; ROLE="hq"; NODE_ID="HQ"; DIR="/var/www/synclytics"; CI_MODE=0; PHP_V="8.4"; PG_MAJOR=17
while [ $# -gt 0 ]; do
  case "$1" in
    --zip) ZIP="$2"; shift 2 ;;
    --source) SRC="$2"; shift 2 ;;          # an extracted release or a source checkout (folder holding server/)
    --domain) DOMAIN="$2"; shift 2 ;;
    --email) EMAIL="$2"; shift 2 ;;
    --role) ROLE="$2"; shift 2 ;;
    --node-id) NODE_ID="$2"; shift 2 ;;
    --dir) DIR="$2"; shift 2 ;;
    --ci) CI_MODE=1; shift ;;                # automated test: no certificate, no interactive company creation
    -h|--help) sed -n '2,11p' "$0"; exit 0 ;;
    *) echo "Unknown option $1"; exit 1 ;;
  esac
done

say() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
die() { printf '\n\033[1;31mStopped: %s\033[0m\n' "$*"; exit 1; }
[ "$(id -u)" -eq 0 ] || die "run it with sudo"
[ -n "$ZIP$SRC" ] || die "give --zip <release.zip> (or --source <folder>)"
. /etc/os-release
[ "${ID:-}" = "ubuntu" ] || die "this script is for Ubuntu (24.04 recommended)"

say "1/9 Packages: PHP ${PHP_V}, PostgreSQL ${PG_MAJOR}, nginx (why: the server's building blocks, from their official sources)"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q software-properties-common curl ca-certificates gnupg unzip git cron
add-apt-repository -y ppa:ondrej/php >/dev/null
install -d /usr/share/postgresql-common/pgdg
curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc
echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt ${VERSION_CODENAME}-pgdg main" > /etc/apt/sources.list.d/pgdg.list
apt-get update -q
apt-get install -y -q nginx "php${PHP_V}-fpm" "php${PHP_V}-cli" "php${PHP_V}-pgsql" "php${PHP_V}-intl" "php${PHP_V}-bcmath" "php${PHP_V}-mbstring" \
  "php${PHP_V}-xml" "php${PHP_V}-curl" "php${PHP_V}-zip" "php${PHP_V}-gd" "postgresql-${PG_MAJOR}" "postgresql-client-${PG_MAJOR}"
if ! command -v composer >/dev/null; then
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
fi

say "2/9 The software in ${DIR}"
mkdir -p "$DIR"
if [ -n "$ZIP" ]; then
  unzip -oq "$ZIP" -d /tmp/synclytics-release
  SRC="$(dirname "$(find /tmp/synclytics-release -maxdepth 3 -name artisan -path '*server/artisan' | head -1)")/.."
fi
[ -f "$SRC/server/artisan" ] || die "no server/artisan in the release"
# Code is replaced; .env, storage (uploads, backups, logs) are kept on updates.
rsync -a --delete --exclude '.env' --exclude 'storage/' --exclude 'vendor/' "$SRC/server/" "$DIR/server/" 2>/dev/null \
  || { apt-get install -y -q rsync && rsync -a --delete --exclude '.env' --exclude 'storage/' --exclude 'vendor/' "$SRC/server/" "$DIR/server/"; }
mkdir -p "$DIR/server/storage/app/private" "$DIR/server/storage/framework/"{cache,sessions,views} "$DIR/server/storage/logs" "$DIR/server/bootstrap/cache"
cd "$DIR/server"

say "3/9 The database (why: everything the business records lives here)"
ENV_FILE="$DIR/server/.env"
# PostgreSQL 17's own cluster (another version already on the machine keeps its port; 17 then gets the next one).
pg_lsclusters -h | awk -v v="${PG_MAJOR}" '$1==v && $2=="main"' | grep -q . || pg_createcluster "${PG_MAJOR}" main >/dev/null  # some images turn off the automatic cluster
pg_ctlcluster "${PG_MAJOR}" main start 2>/dev/null || true
PG_PORT="$(pg_lsclusters -h | awk -v v="${PG_MAJOR}" '$1==v && $2=="main" {print $3}')"; PG_PORT="${PG_PORT:-5432}"
PSQL="sudo -u postgres psql -p ${PG_PORT}"
if [ ! -f "$ENV_FILE" ]; then
  DB_PASS="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)"
  $PSQL -qc "DO \$\$BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname='erp') THEN CREATE ROLE erp LOGIN PASSWORD '${DB_PASS}' CREATEDB; ELSE ALTER ROLE erp PASSWORD '${DB_PASS}' CREATEDB; END IF; END\$\$;"
  $PSQL -tc "SELECT 1 FROM pg_database WHERE datname='erp'" | grep -q 1 || sudo -u postgres createdb -p "${PG_PORT}" -O erp erp
  cp .env.example .env
  URL="http://$(hostname -I | awk '{print $1}')"; [ -n "$DOMAIN" ] && URL="https://${DOMAIN}"
  sed -i -e "s|^APP_ENV=.*|APP_ENV=production|" -e "s|^APP_DEBUG=.*|APP_DEBUG=false|" -e "s|^APP_URL=.*|APP_URL=${URL}|" \
    -e "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|" -e "s|^DB_PORT=.*|DB_PORT=${PG_PORT}|" -e "s|^ERP_NODE_ROLE=.*|ERP_NODE_ROLE=${ROLE}|" -e "s|^ERP_NODE_ID=.*|ERP_NODE_ID=${NODE_ID}|" \
    -e "s|^BACKUP_PG_BIN=.*|BACKUP_PG_BIN=/usr/lib/postgresql/${PG_MAJOR}/bin|" .env
  [ "$CI_MODE" = 1 ] && sed -i -e "s|^APP_URL=.*|APP_URL=http://localhost|" .env
  chmod 640 .env
  NEW_INSTALL=1
else
  NEW_INSTALL=0
fi

say "4/9 Libraries (composer)"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --no-progress

say "5/9 Keys (created once, never changed — they are in your backups)"
grep -q '^APP_KEY=base64' .env || php artisan key:generate --force --no-interaction
grep -q '^ERP_AUDIT_KEY=.\+' .env || php artisan erp:audit-key --no-interaction
PASSPHRASE_OUT="$(php artisan erp:backup:key --no-interaction)"

say "6/9 Database structure and standard data"
php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction
if [ ! -f public/pos/index.html ] && [ -f "$SRC/pos/package.json" ]; then
  say "   Building the till app from source (why: the release did not include it)"
  command -v npm >/dev/null || { curl -fsSL https://deb.nodesource.com/setup_24.x | bash - >/dev/null && apt-get install -y -q nodejs; }
  (cd "$SRC/pos" && npm ci --no-audit --no-fund && POS_OUT_DIR="$DIR/server/public/pos" npm run build:server)
fi
php artisan storage:link --no-interaction >/dev/null 2>&1 || true
php artisan optimize --no-interaction
chown -R www-data:www-data "$DIR/server/storage" "$DIR/server/bootstrap/cache"
chgrp www-data .env

say "7/9 Web server (nginx + PHP-FPM)"
cat > /etc/nginx/sites-available/synclytics <<NGINX
server {
    listen 80;
    server_name ${DOMAIN:-_};
    root ${DIR}/server/public;
    index index.php;
    client_max_body_size 25m;
    location /pos/ { try_files \$uri /pos/index.html; }
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/php${PHP_V}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }
    location ~ /\.(?!well-known) { deny all; }
}
NGINX
ln -sf /etc/nginx/sites-available/synclytics /etc/nginx/sites-enabled/synclytics
rm -f /etc/nginx/sites-enabled/default
sed -i -e 's/^upload_max_filesize.*/upload_max_filesize = 20M/' -e 's/^post_max_size.*/post_max_size = 25M/' -e 's/^memory_limit.*/memory_limit = 512M/' \
  -e 's|^;\?date.timezone.*|date.timezone = Africa/Accra|' "/etc/php/${PHP_V}/fpm/php.ini"
nginx -t
systemctl reload nginx 2>/dev/null || service nginx restart
systemctl restart "php${PHP_V}-fpm" 2>/dev/null || service "php${PHP_V}-fpm" restart

say "8/9 Scheduler and live updates (why: posting, sync, messages, backups, reports run every minute)"
echo "* * * * * www-data cd ${DIR}/server && php artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/synclytics
if [ "$CI_MODE" = 0 ] && command -v systemctl >/dev/null; then
  cat > /etc/systemd/system/synclytics-reverb.service <<UNIT
[Unit]
Description=Synclytics live updates (Reverb)
After=network.target
[Service]
User=www-data
WorkingDirectory=${DIR}/server
ExecStart=/usr/bin/php artisan reverb:start --host=0.0.0.0 --port=8080
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
UNIT
  systemctl daemon-reload && systemctl enable --now synclytics-reverb
fi

say "9/9 HTTPS"
if [ -n "$DOMAIN" ] && [ -n "$EMAIL" ] && [ "$CI_MODE" = 0 ]; then
  apt-get install -y -q certbot python3-certbot-nginx
  certbot --nginx -d "$DOMAIN" -m "$EMAIL" --agree-tos --non-interactive --redirect
  sed -i "s|^SESSION_SECURE_COOKIE=.*||" .env && echo "SESSION_SECURE_COOKIE=true" >> .env && php artisan optimize --no-interaction
else
  echo "   Skipped (no --domain/--email): use this only inside your network, or add HTTPS before opening it to the internet (Chapter 5)."
fi

curl -fs http://localhost/up >/dev/null && say "Done: the server answers at $(grep '^APP_URL=' .env | cut -d= -f2)/admin" || die "the web server does not answer on http://localhost/up — see storage/logs/laravel.log"
echo "$PASSPHRASE_OUT" | grep -q 'already set' || { echo; echo "$PASSPHRASE_OUT"; }
if [ "$NEW_INSTALL" = 1 ] && [ "$ROLE" = "hq" ] && [ "$CI_MODE" = 0 ]; then
  say "Last step: create your company and the owner account"
  php artisan erp:install
fi
