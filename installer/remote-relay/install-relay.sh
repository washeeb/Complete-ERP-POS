#!/usr/bin/env bash
# The remote support relay (SyncLytics) — one-command installation on any VPS running Ubuntu 22.04 / 24.04 or Debian 12
# (Contabo, Hetzner, DigitalOcean, AWS Lightsail, Vultr, a server in your own office…). It installs MeshCentral (open
# source, Apache 2.0): the relay that tills, PCs and Android handhelds connect out to, so support can see or control
# them without opening anything on the shop's network.
#
#   sudo bash install-relay.sh --domain remote.example.com --email you@example.com [--admin support]
#
# Before running it: point the domain's DNS (an A record) at this server's public IP address, so the free Let's Encrypt
# certificate can be issued. Ports 80 and 443 must be reachable from the internet.
# Safe to run again (an update): it keeps the configuration, the accounts, the device groups and the recordings.
set -euo pipefail

DOMAIN=""; EMAIL=""; ADMIN="support"; DIR="/opt/meshcentral"; NODE_MAJOR=20
while [ $# -gt 0 ]; do
  case "$1" in
    --domain) DOMAIN="$2"; shift 2 ;;
    --email) EMAIL="$2"; shift 2 ;;
    --admin) ADMIN="$2"; shift 2 ;;
    --dir) DIR="$2"; shift 2 ;;
    -h|--help) sed -n '2,11p' "$0"; exit 0 ;;
    *) echo "Unknown option $1"; exit 1 ;;
  esac
done

say() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
die() { printf '\n\033[1;31mStopped: %s\033[0m\n' "$*"; exit 1; }
trap 'printf "\n\033[1;31mStopped: an unexpected error on line %s of install-relay.sh (running it again is safe).\033[0m\n" "$LINENO"' ERR

[ "$(id -u)" -eq 0 ] || die "run it with sudo"
[ -n "$DOMAIN" ] || die "give the relay's internet name: --domain remote.example.com"
[ -n "$EMAIL" ] || die "give an e-mail address for the certificate notices: --email you@example.com"
echo "$DOMAIN" | grep -Eq '^[a-z0-9.-]+\.[a-z]{2,}$' || die "--domain must be a name like remote.example.com (not an IP address)"
. /etc/os-release
case "${ID}:${VERSION_ID}" in
  ubuntu:22.04|ubuntu:24.04|debian:12) ;;
  *) die "this script supports Ubuntu 22.04 / 24.04 and Debian 12 (found ${PRETTY_NAME})" ;;
esac

say "1/6 System packages"
apt-get update -q
apt-get install -y -q curl ca-certificates gnupg ufw

say "2/6 Node.js ${NODE_MAJOR} (LTS)"
if ! command -v node >/dev/null || [ "$(node -p 'process.versions.node.split(".")[0]')" -lt "$NODE_MAJOR" ]; then
  install -d -m 0755 /etc/apt/keyrings
  curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor --yes -o /etc/apt/keyrings/nodesource.gpg
  echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_${NODE_MAJOR}.x nodistro main" > /etc/apt/sources.list.d/nodesource.list
  apt-get update -q && apt-get install -y -q nodejs
fi

say "3/6 MeshCentral in ${DIR}"
id meshcentral >/dev/null 2>&1 || useradd --system --home "$DIR" --shell /usr/sbin/nologin meshcentral
install -d -o meshcentral -g meshcentral "$DIR" "$DIR/meshcentral-data" "$DIR/meshcentral-recordings"
cd "$DIR"
[ -f package.json ] || sudo -u meshcentral npm init -y >/dev/null
sudo -u meshcentral npm install --no-audit --no-fund meshcentral@latest >/dev/null

say "4/6 Configuration (kept when it already exists)"
CONFIG="$DIR/meshcentral-data/config.json"
if [ ! -f "$CONFIG" ]; then
  cat > "$CONFIG" <<JSON
{
  "settings": {
    "cert": "${DOMAIN}",
    "port": 443,
    "redirPort": 80,
    "aliasPort": 443,
    "_comment": "Agents and browsers connect to https://${DOMAIN}. Nobody can create an account by themselves.",
    "allowLoginToken": false,
    "newAccounts": false,
    "selfUpdate": false
  },
  "domains": {
    "": {
      "title": "SyncLytics remote support",
      "newAccounts": false,
      "userNameIsEmail": false,
      "passwordRequirements": { "min": 12, "upper": 1, "lower": 1, "numeric": 1, "force2factor": true },
      "agentCustomization": { "displayName": "SyncLytics remote support", "description": "Lets SyncLytics support help with this device.", "companyName": "SyncLytics", "serviceName": "SyncLyticsSupport" },
      "sessionRecording": { "filepath": "${DIR}/meshcentral-recordings", "index": true, "protocols": [1, 2, 5] },
      "consentMessages": { "title": "SyncLytics remote support", "desktop": "{0} from SyncLytics support asks to see this screen.", "terminal": "{0} asks to open a command window.", "files": "{0} asks to look at files." }
    }
  },
  "letsencrypt": { "email": "${EMAIL}", "names": "${DOMAIN}", "production": true }
}
JSON
  chown meshcentral:meshcentral "$CONFIG"
fi

say "5/6 Service and firewall"
cat > /etc/systemd/system/meshcentral.service <<UNIT
[Unit]
Description=SyncLytics remote support relay (MeshCentral)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=meshcentral
WorkingDirectory=${DIR}
ExecStart=/usr/bin/node ${DIR}/node_modules/meshcentral
Restart=always
RestartSec=10
# Allow the relay to use ports 80 and 443 without running as root.
AmbientCapabilities=CAP_NET_BIND_SERVICE
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable --now meshcentral
ufw allow OpenSSH >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw --force enable >/dev/null

say "6/6 The first support account"
if ! sudo -u meshcentral test -f "$DIR/meshcentral-data/.admin-created"; then
  PASS="$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 20)A1"
  systemctl stop meshcentral
  sudo -u meshcentral node "$DIR/node_modules/meshcentral" --createaccount "$ADMIN" --pass "$PASS" --email "$EMAIL" >/dev/null
  sudo -u meshcentral node "$DIR/node_modules/meshcentral" --adminaccount "$ADMIN" >/dev/null
  sudo -u meshcentral touch "$DIR/meshcentral-data/.admin-created"
  systemctl start meshcentral
  printf '\n  Account: %s\n  Temporary password: %s\n  Sign in at https://%s, change the password and set up two-factor sign-in (required).\n' "$ADMIN" "$PASS" "$DOMAIN"
fi

printf '\n\033[1;32mThe relay is running at https://%s\033[0m\n' "$DOMAIN"
cat <<NEXT

Next (installation guide, chapter 20):
  1. Sign in, change the password, set up two-factor sign-in.
  2. For each client, create a device group named after the client, with user consent set as agreed with them.
  3. On each client's business server, set REMOTE_RELAY_URL=https://${DOMAIN} and the group's invitation link
     (System → Settings → Remote access), then install the agent on their tills and handhelds from that link.
  Session recordings are kept in ${DIR}/meshcentral-recordings.
NEXT
