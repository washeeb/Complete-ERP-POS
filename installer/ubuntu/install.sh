#!/usr/bin/env bash
# The business server (SES Labs) — one-command installation on a plain Ubuntu 24.04 server (setups B, C, D and branch servers),
# doing what Chapter 5 of the installation guide describes by hand.
#
#   sudo bash install.sh --zip ~/ses-labs-<customer>-<build>.zip [--domain erp.example.com --email you@example.com]
#                        [--role hq|branch --node-id HQ] [--dir /var/www/seslabs]
#
# Always HTTPS (Caddy): with --domain a free Let's Encrypt certificate; without it the server's own certificate for its
# IP address and name on your network, which each device trusts once (Chapter 4.9).
# Safe to run again (an update): it keeps .env, the database and every key, and only adds what is missing.
set -euo pipefail

SLUG="seslabs" # the product name in folder, service and site names
ZIP=""; SRC=""; DOMAIN=""; EMAIL=""; ROLE="hq"; NODE_ID="HQ"; DIR="/var/www/${SLUG}"; CI_MODE=0; PHP_V="8.4"; PG_MAJOR=17
while [ $# -gt 0 ]; do
  case "$1" in
    --zip) ZIP="$2"; shift 2 ;;
    --source) SRC="$2"; shift 2 ;;          # an extracted release or a source checkout (folder holding server/)
    --domain) DOMAIN="$2"; shift 2 ;;
    --email) EMAIL="$2"; shift 2 ;;
    --role) ROLE="$2"; shift 2 ;;
    --node-id) NODE_ID="$2"; shift 2 ;;
    --dir) DIR="$2"; shift 2 ;;
    --ci) CI_MODE=1; shift ;;                # automated test: localhost only, no firewall, no interactive company creation
    -h|--help) sed -n '2,10p' "$0"; exit 0 ;;
    *) echo "Unknown option $1"; exit 1 ;;
  esac
done

say() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
die() { printf '\n\033[1;31mStopped: %s\033[0m\n' "$*"; exit 1; }
set_env() { # replace KEY=... in .env, or add it
  if grep -q "^$1=" .env; then sed -i "s|^$1=.*|$1=$2|" .env; else printf '\n%s=%s\n' "$1" "$2" >> .env; fi
}
[ "$(id -u)" -eq 0 ] || die "run it with sudo"
[ -n "$ZIP$SRC" ] || die "give --zip <release.zip> (or --source <folder>)"
command -v systemctl >/dev/null || die "this script needs systemd (a normal Ubuntu server); for containers use installer/docker"
. /etc/os-release
[ "${ID:-}" = "ubuntu" ] || die "this script is for Ubuntu (24.04 recommended)"
DOMAIN="$(echo "$DOMAIN" | tr 'A-Z' 'a-z')"

# Where the system is reached: an internet name (Let's Encrypt), or this server's address and name on the network
# (its own certificate authority). The test run uses localhost only.
LAN_IP="$(hostname -I | awk '{print $1}')"
HOST="$(hostname -s | tr 'A-Z' 'a-z')"
if [ -n "$DOMAIN" ]; then SITES="$DOMAIN"; FIRST="$DOMAIN"
elif [ "$CI_MODE" = 1 ]; then SITES="localhost"; FIRST="localhost"
else SITES="${LAN_IP}, ${HOST}"; FIRST="$LAN_IP"
fi
CADDY_DATA="/var/lib/caddy/.local/share/caddy"

say "1/10 Packages: PHP ${PHP_V}, PostgreSQL ${PG_MAJOR}, Caddy (why: the server's building blocks, from their official sources)"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q software-properties-common curl ca-certificates gnupg unzip git cron debian-keyring debian-archive-keyring apt-transport-https
add-apt-repository -y ppa:ondrej/php >/dev/null
install -d /usr/share/postgresql-common/pgdg
curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc
echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt ${VERSION_CODENAME}-pgdg main" > /etc/apt/sources.list.d/pgdg.list
# Caddy's own signed package source (the web server; it fetches and renews certificates by itself).
curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/gpg.key | gpg --dearmor --yes -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt -o /etc/apt/sources.list.d/caddy-stable.list
apt-get update -q
apt-get install -y -q caddy "php${PHP_V}-fpm" "php${PHP_V}-cli" "php${PHP_V}-pgsql" "php${PHP_V}-intl" "php${PHP_V}-bcmath" "php${PHP_V}-mbstring" \
  "php${PHP_V}-xml" "php${PHP_V}-curl" "php${PHP_V}-zip" "php${PHP_V}-gd" "postgresql-${PG_MAJOR}" "postgresql-client-${PG_MAJOR}"
if ! command -v composer >/dev/null; then
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
fi

say "2/10 The software in ${DIR}"
mkdir -p "$DIR"
if [ -n "$ZIP" ]; then
  unzip -oq "$ZIP" -d /tmp/${SLUG}-release
  SRC="$(dirname "$(find /tmp/${SLUG}-release -maxdepth 3 -name artisan -path '*server/artisan' | head -1)")/.."
fi
[ -f "$SRC/server/artisan" ] || die "no server/artisan in the release"
# Code is replaced; .env, storage (uploads, backups, logs) are kept on updates.
rsync -a --delete --exclude '.env' --exclude 'storage/' --exclude 'vendor/' "$SRC/server/" "$DIR/server/" 2>/dev/null \
  || { apt-get install -y -q rsync && rsync -a --delete --exclude '.env' --exclude 'storage/' --exclude 'vendor/' "$SRC/server/" "$DIR/server/"; }
mkdir -p "$DIR/server/storage/app/private" "$DIR/server/storage/framework/"{cache,sessions,views} "$DIR/server/storage/logs" "$DIR/server/bootstrap/cache"
cd "$DIR/server"

say "3/10 The database (why: everything the business records lives here)"
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
  sed -i -e "s|^APP_ENV=.*|APP_ENV=production|" -e "s|^APP_DEBUG=.*|APP_DEBUG=false|" \
    -e "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|" -e "s|^DB_PORT=.*|DB_PORT=${PG_PORT}|" -e "s|^ERP_NODE_ROLE=.*|ERP_NODE_ROLE=${ROLE}|" -e "s|^ERP_NODE_ID=.*|ERP_NODE_ID=${NODE_ID}|" \
    -e "s|^BACKUP_PG_BIN=.*|BACKUP_PG_BIN=/usr/lib/postgresql/${PG_MAJOR}/bin|"     -e "s|^LOG_LEVEL=.*|LOG_LEVEL=warning|" -e "s|^CACHE_STORE=.*|CACHE_STORE=file|" .env
  chmod 640 .env
  NEW_INSTALL=1
else
  NEW_INSTALL=0
fi
# Every run (also updates): the https address, secure cookies, and live updates through the web server (browsers and
# tills on 443; the server itself sends to the live-updates service on this machine). The app keys are made once.
set_env APP_URL "https://${FIRST}"
set_env SESSION_SECURE_COOKIE true
set_env REVERB_HOST "$FIRST"; set_env REVERB_PORT 443; set_env REVERB_SCHEME https
set_env REVERB_PUBLISH_HOST 127.0.0.1; set_env REVERB_PUBLISH_PORT 8080; set_env REVERB_PUBLISH_SCHEME http
for k in REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET; do
  grep -Eq "^${k}=[A-Za-z0-9]{16,}" .env || set_env "$k" "$(head -c 32 /dev/urandom | base64 | tr -dc 'a-z0-9' | head -c 24)"
done

say "4/10 Libraries (composer)"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --no-progress

say "5/10 Keys (created once, never changed — they are in your backups)"
grep -q '^APP_KEY=base64' .env || php artisan key:generate --force --no-interaction
grep -q '^ERP_AUDIT_KEY=.\+' .env || php artisan erp:audit-key --no-interaction
PASSPHRASE_OUT="$(php artisan erp:backup:key --no-interaction)"

say "6/10 Database structure and standard data"
php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction
if [ ! -f public/pos/index.html ] && [ -f "$SRC/pos/package.json" ]; then
  say "   Building the till app from source (why: the release did not include it)"
  command -v npm >/dev/null || { curl -fsSL https://deb.nodesource.com/setup_24.x | bash - >/dev/null && apt-get install -y -q nodejs; }
  (cd "$SRC/pos" && npm ci --no-audit --no-fund && POS_OUT_DIR="$DIR/server/public/pos" npm run build:server)
fi
php artisan storage:link --no-interaction >/dev/null 2>&1 || true
php artisan optimize --no-interaction
php artisan filament:optimize --no-interaction # caches the screens' building blocks and icons
chown -R www-data:www-data "$DIR/server/storage" "$DIR/server/bootstrap/cache"
chgrp www-data .env

say "7/10 Web server (Caddy + PHP-FPM, https only)"
# An earlier installation used nginx: retire its site. nginx is stopped only if it serves nothing else.
rm -f /etc/nginx/sites-enabled/${SLUG} /etc/nginx/sites-available/${SLUG}
if systemctl is-active --quiet nginx 2>/dev/null; then
  if [ -z "$(ls -A /etc/nginx/sites-enabled 2>/dev/null)" ]; then systemctl disable --now nginx >/dev/null 2>&1
  else die "nginx is serving other sites on this server (/etc/nginx/sites-enabled), and ports 80 and 443 are needed for ${SLUG}. Move them first."; fi
fi
if ss -ltnpH '( sport = :80 or sport = :443 )' | grep -v caddy | grep -q .; then
  die "another program uses port 80 or 443: $(ss -ltnpH '( sport = :80 or sport = :443 )' | grep -v caddy | head -1)"
fi
sed -i -e 's/^upload_max_filesize.*/upload_max_filesize = 20M/' -e 's/^post_max_size.*/post_max_size = 25M/' -e 's/^memory_limit.*/memory_limit = 512M/' \
  -e 's|^;\?date.timezone.*|date.timezone = Africa/Accra|' "/etc/php/${PHP_V}/fpm/php.ini"
# Speed: keep the compiled application in memory (OPcache), checked for changes once a minute, and remember file locations.
cat > "/etc/php/${PHP_V}/fpm/conf.d/99-synclytics-speed.ini" <<'INI'
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=60
realpath_cache_size=4096K
realpath_cache_ttl=600
INI
usermod -aG www-data caddy # to reach PHP-FPM's socket (owned by www-data)
GLOBAL="	admin off
	skip_install_trust"
[ "$CI_MODE" = 1 ] && GLOBAL="${GLOBAL}
	default_bind 127.0.0.1"
[ -n "$DOMAIN" ] && [ -n "$EMAIL" ] && GLOBAL="${GLOBAL}
	email ${EMAIL}"
TLS="	tls internal"; [ -n "$DOMAIN" ] && TLS=""
{
  cat <<CADDY
# Written by install.sh: changes are lost on the next update. Installation guide, Chapter 5.7.
{
${GLOBAL}
	pki {
		ca local {
			name "SES Labs on ${HOST}"
			root_cn "SES Labs ${HOST} Root"
			intermediate_cn "SES Labs ${HOST} Intermediate"
		}
	}
}
CADDY
  if [ -z "$DOMAIN" ] && [ "$CI_MODE" = 0 ]; then
    # Plain http only hands out this server's certificate (so devices can trust it) and sends everything else to https.
    cat <<CADDY

http://${LAN_IP}, http://${HOST} {
	handle /ca.crt {
		root * ${CADDY_DATA}/pki/authorities/local
		rewrite * /root.crt
		header Content-Type application/x-x509-ca-cert
		file_server
	}
	handle {
		redir https://{host}{uri} permanent
	}
}
CADDY
  fi
  cat <<CADDY

${SITES} {
${TLS}
	root * ${DIR}/server/public
	encode zstd gzip
	request_body {
		max_size 25MB
	}
	header -Server
	# Files whose names or links change with each release (the till's code, the screens' styles and scripts) are kept by
	# the browser for a year instead of being asked for again on every page.
	@versioned path /pos/assets/* /build/* /css/* /js/* /fonts/* /vendor/*
	header @versioned Cache-Control "public, max-age=31536000, immutable"
	# One handle runs per request. Hidden files (.env and the like) never leave the server.
	@hidden {
		path_regexp /\\.
		not path /.well-known/*
	}
	handle @hidden {
		respond 404
	}
	# Live updates (Reverb) on the same https address, so no other port is open.
	@live path /app/* /apps/*
	handle @live {
		reverse_proxy 127.0.0.1:8080
	}
	# The till app (single-page, works offline): unknown paths fall back to its index.
	handle /pos/* {
		header /pos/sw.js Cache-Control no-cache
		try_files {path} /pos/index.html
		file_server
	}
	handle {
		php_fastcgi unix//run/php/php${PHP_V}-fpm.sock {
			read_timeout 300s
		}
		file_server
	}
}
CADDY
} > /etc/caddy/Caddyfile
sudo -u caddy caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>&1 || { sudo -u caddy caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile; die "the web server settings are not valid (see above)"; }
systemctl restart "php${PHP_V}-fpm"
systemctl enable caddy >/dev/null 2>&1
systemctl restart caddy # restart, not reload: the admin endpoint is off

say "8/10 Scheduler and live updates (why: posting, sync, messages, backups, reports run every minute)"
echo "* * * * * www-data cd ${DIR}/server && php artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/${SLUG}
cat > /etc/systemd/system/${SLUG}-reverb.service <<UNIT
[Unit]
Description=${SLUG} live updates (Reverb)
After=network.target
[Service]
User=www-data
WorkingDirectory=${DIR}/server
ExecStart=/usr/bin/php artisan reverb:start --host=127.0.0.1 --port=8080
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload && systemctl enable ${SLUG}-reverb >/dev/null 2>&1 && systemctl restart ${SLUG}-reverb

say "9/10 Certificate and firewall"
if [ -z "$DOMAIN" ]; then
  # Trust this server's own certificate authority here too (for checks and for PHP), and keep a copy for devices.
  ROOT="${CADDY_DATA}/pki/authorities/local/root.crt"
  for _ in $(seq 1 30); do [ -f "$ROOT" ] && break; sleep 2; done
  [ -f "$ROOT" ] || die "the web server did not start: see journalctl -u caddy"
  cp "$ROOT" "/usr/local/share/ca-certificates/${SLUG}-${HOST}.crt" && update-ca-certificates >/dev/null
  cp "$ROOT" "$DIR/${SLUG}-certificate.crt"
fi
if [ "$CI_MODE" = 0 ] && command -v ufw >/dev/null; then
  # Only remote administration (SSH, on whatever port it uses) and the web server's two ports.
  SSH_PORT="$(sshd -T 2>/dev/null | awk '/^port /{print $2; exit}')"
  ufw allow "${SSH_PORT:-22}/tcp" >/dev/null
  ufw allow 80/tcp >/dev/null && ufw allow 443/tcp >/dev/null
  ufw delete allow 8080/tcp >/dev/null 2>&1 || true
  ufw --force enable >/dev/null
fi

say "10/10 Check"
OK=0
for _ in $(seq 1 30); do
  if [ -n "$DOMAIN" ]; then
    # The internet name may not point here yet, or its certificate may still be on its way: ask this machine directly.
    curl -fsk --resolve "${DOMAIN}:443:127.0.0.1" "https://${DOMAIN}/up" >/dev/null 2>&1 && { OK=1; break; }
  else
    curl -fs "https://${FIRST}/up" >/dev/null 2>&1 && { OK=1; break; }
  fi
  sleep 2
done
[ "$OK" = 1 ] || die "the server does not answer on https://${FIRST}/up: see ${DIR}/server/storage/logs/laravel.log and journalctl -u caddy"
say "Done: the server answers at https://${FIRST}/admin"
[ -z "$DOMAIN" ] && [ "$CI_MODE" = 0 ] && echo "   Tills, tablets and other PCs: open http://${FIRST}/ca.crt once and trust it (installation guide, Chapter 4.9)."
echo "$PASSPHRASE_OUT" | grep -q 'already set' || { echo; echo "$PASSPHRASE_OUT"; }
if [ "$NEW_INSTALL" = 1 ] && [ "$ROLE" = "hq" ] && [ "$CI_MODE" = 0 ]; then
  say "Last step: create your company and the owner account"
  php artisan erp:install
fi
