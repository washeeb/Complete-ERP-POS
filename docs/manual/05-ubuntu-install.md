# 5. Installing the server on Ubuntu Linux (setups B, C, D and branch servers)

Ubuntu is the recommended system for a head-office, central or cloud server, and for any shop server with more than four devices. It runs for months without restarts and handles many tills at once.

This chapter assumes **Ubuntu Server 24.04 LTS**, freshly installed, on a cloud server (VPS) or a computer at your premises. You run every command as a user that may use `sudo` (the cloud provider's default user, often `ubuntu` or `root`).

## 5.0 The quick ways (recommended)

**A. The install script** — does everything in 5.2–5.9 on a plain Ubuntu server:

1. Do 5.1 (get the server, connect to it). Copy the release ZIP and `install.sh` (both from SyncLytics) to the server, e.g. with WinSCP.
2. Run it. Add your own domain and e-mail if the server has an internet name (setup D); leave both out for a server used only inside your network:
   ```
   sudo bash install.sh --zip ~/ses-labs-<customer>-<build>.zip --domain erp.yourcompany.com --email you@yourcompany.com
   ```
   For a branch server add `--role branch --node-id B01`.
3. Write down the **backup passphrase** it shows, then answer its questions to create your company and the owner.

The server is then reached over **https** only:
- **With a domain:** a free Let's Encrypt certificate is fetched and renewed automatically.
- **Without one:** the server uses its own certificate for its network address and name. Each till, tablet and PC trusts it once, as in **4.9**, from `http://<server-address>/ca.crt`.

The firewall is switched on with only SSH, 80 and 443 open (443 also for HTTP/3).

Running it again with a newer ZIP updates the software and keeps your data, `.env` and keys. An older installation that used nginx is moved to the new web server (Caddy) automatically.

**A shared server for small clients (SyncLytics staff).** The standard is **one server per client**. For small businesses that are just starting, several clients can share one server, each as its **own separate copy** of the system on its own internet name:

```
sudo bash install.sh --zip <release>.zip --instance kofi-pharmacy --domain kofi.synclytics.example --email you@example.com
```

- `--instance` is a short name for the client: lowercase letters, digits and dashes.
- Each copy gets its own:
  - folder (`/var/www/seslabs-kofi-pharmacy`) and database (`erp_kofi_pharmacy`);
  - system user, so one copy cannot read another's files, backups or passwords;
  - PHP workers, which stop when idle so a quiet client uses little memory;
  - live-updates port, backups, licence (its own installation code) and update timing.
- Give each copy its own **DNS A record** pointing to the same server (5.1), and its own `--domain`.
- **Update one client:** run the same command with the new ZIP and the same `--instance`. It backs that copy up first and leaves the others alone.
- **How many:** a server with 12 GB of memory (for example Contabo Cloud VPS 6) holds about **3 small clients** (one or two shops each). Move a client to a server of its own when it grows, or when *System → Health → Slow pages* starts filling up: back it up, install it on the new server, restore the backup there (Chapter 12.5), and point its internet name to the new server.
- **Remove a client's copy** (after a final backup and when the contract has ended):
  ```
  sudo systemctl disable --now seslabs-<name>-reverb
  sudo rm /etc/cron.d/seslabs-<name> /etc/caddy/sites/seslabs-<name>.caddy /etc/php/8.4/fpm/pool.d/seslabs-<name>.conf
  sudo systemctl restart php8.4-fpm caddy
  ```
  Then delete its folder and database (`sudo -u postgres dropdb erp_<name>`) only once you are sure the backup is safe elsewhere.

**Why separate copies, not one copy holding several companies:** one copy shares one licence, one backup and one update for everyone in it. Restoring one client's backup would roll back the others, and a fault in one would reach them all.

**B. Docker** (if your host prefers containers):

1. Install Docker (`curl -fsSL https://get.docker.com | sudo sh`).
2. In the release folder: `cp installer/docker/.env.docker.example installer/docker/.env` and edit it: `APP_URL`, a long random `DB_PASSWORD`, a `BACKUP_PASSPHRASE` (12+ characters, written down), and e-mail settings (Chapter 16).
3. `sudo docker compose -f installer/docker/docker-compose.yml up -d --build`
4. The first start prints an `APP_KEY` and `ERP_AUDIT_KEY` in `docker compose logs app`: copy both into `installer/docker/.env` straight away — they must never change.
5. Create your company: `sudo docker compose -f installer/docker/docker-compose.yml exec app php artisan erp:install`.
6. Put HTTPS in front (your host's load balancer, or Caddy / Traefik).

**Why Docker:** the whole server (PHP, web server, scheduler, live updates) is one image that runs the same everywhere; the database and your files live in Docker volumes that survive updates (`up -d --build` with a new release).

The rest of this chapter is the manual way.

## 5.1 Get the server and connect to it

### Cloud server (setup D)

1. Rent a VPS from any provider (for example a provider with a data centre in or near West Africa or Europe; with Contabo, the *Cloud VPS 6* with 100 GB NVMe storage in the *European Union* region, with Auto Backup). Choose **Ubuntu 24.04**, at least 2 vCPU, 4 GB RAM, 80 GB SSD; see the sizing table in Chapter 3. Note the server's **public IP address** and the password or key you are given.
2. Buy or use a **domain name** and create a **DNS A record** such as `erp.yourshop.com` pointing to that IP address (in your domain provider's control panel). It can take up to an hour to work.
   **Why:** tills, browsers and the HTTPS certificate need a name, not just a number.

### Server at your premises (setups B, C, branch servers)

1. Install Ubuntu Server 24.04 from a USB stick (`https://ubuntu.com/download/server`). During installation choose **Install OpenSSH server**.
2. Reserve a fixed IP address for it in your router (see 4.1, step 4).

### Connect from your Windows PC

Open PowerShell on your own PC and type (replace the user and address):

```
ssh ubuntu@<server-ip-address>
```

Type `yes` the first time, then the password. You are now typing commands **on the server**.

**Tip:** to copy files to the server with a mouse, install **WinSCP** (`https://winscp.net`) on your PC and connect with the same address, user and password.

## 5.2 Update the system and set the time

```
sudo apt update && sudo apt upgrade -y
sudo timedatectl set-timezone Africa/Accra
sudo timedatectl set-ntp true
```

**Why:** security fixes first; and the correct time is essential for receipts, shifts and sync.

**Check:** `timedatectl` shows *System clock synchronized: yes*.

## 5.3 Install PostgreSQL 17

```
sudo apt install -y postgresql-common
sudo /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -y
sudo apt install -y postgresql-17
```

**Why:** Ubuntu 24.04 ships an older PostgreSQL; the official PostgreSQL repository provides version 17, which the software is tested with.

Create the database and its user (replace `<db-password>` with a long random password and write it down):

```
sudo -u postgres psql -c "CREATE ROLE erp LOGIN PASSWORD '<db-password>';"
sudo -u postgres psql -c "CREATE DATABASE erp OWNER erp ENCODING 'UTF8';"
```

**Check:** the commands answer `CREATE ROLE` and `CREATE DATABASE`.

## 5.4 Install PHP 8.4, Caddy, Composer and Node.js

```
sudo add-apt-repository -y ppa:ondrej/php
sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https curl
curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/gpg.key | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt update
sudo apt install -y caddy unzip git php8.4-fpm php8.4-cli php8.4-pgsql php8.4-intl php8.4-bcmath php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-gd
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

**Why:**
- **PHP** runs the software, and **PHP-FPM** lets several requests run at the same time.
- **Caddy** is the web server that receives the tills' and browsers' requests. It gets and renews https certificates by itself. It comes from its makers' own signed package source.
- **Composer** and **Node.js** are used during installation.

**Check:** `php --version` shows 8.4, `caddy version` shows v2, `composer --version` shows 2.x, `node --version` shows v22 or later, and `php -m` lists `pdo_pgsql`, `intl`, `bcmath` and `sodium`.

## 5.5 Put the software in place

1. Copy your release ZIP to the server (with WinSCP, into your home folder).
2. Unpack it into `/var/www/seslabs`:

```
sudo mkdir -p /var/www/seslabs
sudo unzip ~/ses-labs-*.zip -d /var/www/seslabs
sudo chown -R $USER:www-data /var/www/seslabs
cd /var/www/seslabs/server
```

**Check:** `ls` shows `artisan`, `app`, `public` and other folders.

## 5.6 Configure the server

```
composer install --no-dev --optimize-autoloader
cp .env.example .env
nano .env
```

In the editor, change these lines (move with the arrow keys; save with **Ctrl+O**, **Enter**, exit with **Ctrl+X**):

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://erp.yourshop.com
SESSION_SECURE_COOKIE=true
DB_PASSWORD=<db-password>
ERP_NODE_ROLE=hq
ERP_NODE_ID=HQ
```

- For a server reached by its address on your network, use `APP_URL=https://<server-ip-address>`. It is still https: the server makes its own certificate (5.7).
- For a **branch server**: `ERP_NODE_ROLE=branch` and its id (e.g. `B01`), see Chapter 10.

Then:

```
php artisan key:generate
php artisan erp:audit-key
php artisan migrate --seed --force
php artisan erp:install
npm install && npm run build
php artisan optimize
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod 640 .env && sudo chown $USER:www-data .env
```

The reasons for each command are the same as in Chapter 4, section 4.6. The last two lines let the web server write its logs and cache, and stop other users of the machine from reading `.env`.

**Note:** Skip `php artisan erp:install` on a branch server (Chapter 10).

If you received the source code instead of a release, also build the till app:

```
cd /var/www/seslabs/pos && npm install && npm run build:server && cd ../server
```

## 5.7 Configure the web server (Caddy)

First let Caddy reach PHP:

```
sudo usermod -aG www-data caddy
```

**Why:** PHP-FPM listens on a socket that only the `www-data` group may use.

Replace Caddy's settings file (the install script instead writes a short main file that imports one file per copy from `/etc/caddy/sites/`; the manual way keeps everything in one file):

```
sudo nano /etc/caddy/Caddyfile
```

Delete what is in it and paste the text below. This example is for a server on your network with address `192.168.1.20` and name `shopserver`; change both everywhere.

**For a server with a domain (setup D):**
- write the domain instead of `192.168.1.20, shopserver`;
- remove the whole `http://…` block and the `tls internal` line;
- add `email you@yourshop.com` under `admin off`.

```
{
	admin off
	skip_install_trust
	pki {
		ca local {
			name "SES Labs on shopserver"
			root_cn "SES Labs shopserver Root"
			intermediate_cn "SES Labs shopserver Intermediate"
		}
	}
}

http://192.168.1.20, http://shopserver {
	handle /ca.crt {
		root * /var/lib/caddy/.local/share/caddy/pki/authorities/local
		rewrite * /root.crt
		header Content-Type application/x-x509-ca-cert
		file_server
	}
	handle {
		redir https://{host}{uri} permanent
	}
}

192.168.1.20, shopserver {
	tls internal
	root * /var/www/seslabs/server/public
	encode zstd gzip
	request_body {
		max_size 25MB
	}
	header -Server
	@hidden {
		path_regexp /\.
		not path /.well-known/*
	}
	handle @hidden {
		respond 404
	}
	@live path /app/* /apps/*
	handle @live {
		reverse_proxy 127.0.0.1:8080
	}
	handle /pos/* {
		header /pos/sw.js Cache-Control no-cache
		try_files {path} /pos/index.html
		file_server
	}
	handle {
		php_fastcgi unix//run/php/php8.4-fpm.sock {
			read_timeout 300s
		}
		file_server
	}
}
```

Check and start it:

```
sudo -u caddy caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
sudo systemctl restart php8.4-fpm caddy
```

**Why:**
- *Plain http* only hands out the server's own certificate (so devices can trust it, 4.9) and sends everything else to https.
- *Hidden files* such as `.env` are never served.
- *Live updates* use the same https address.
- *The till app* falls back to its own start page, so it works offline.
- *Everything else* goes to PHP.
- With a domain, Caddy fetches a Let's Encrypt certificate and renews it by itself.
- `admin off` closes Caddy's local control port. After a change to this file, use `systemctl restart caddy` (a *reload* needs that port).

**Server on your network: trust the certificate on the server itself, and keep a copy for devices:**

```
sudo cp /var/lib/caddy/.local/share/caddy/pki/authorities/local/root.crt /usr/local/share/ca-certificates/seslabs.crt
sudo update-ca-certificates
```

**Check:** `validate` ends with *Valid configuration*. `curl https://192.168.1.20/up` (or your domain) answers without a certificate error. On another device that trusts the server (4.9), `https://192.168.1.20/admin` shows the sign-in page.

## 5.8 Firewall

```
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 443/udp
sudo ufw enable
```

**Why:** the firewall blocks everything except remote administration (SSH) and the web server's ports. `443/udp` is HTTP/3, which browsers use when they can: it needs fewer round trips and copes better with a slow or unsteady internet line, which matters for a server far from the shop. The database, PHP and live updates are reachable from the server itself only. If SSH on your server uses another port than 22, allow that port instead of `OpenSSH` **before** `ufw enable`, or you lock yourself out.

**Why HTTPS always:**
- HTTPS encrypts everything between tills and the server: PINs, sales and customer details.
- Browsers only let tills enrol, install as apps and work offline from https addresses (or from `localhost`).

**Warning:** Never run a server that is reachable from the internet without HTTPS.

## 5.9 Background jobs

**The scheduler** runs the routine jobs. Add it to the system's timer (cron):

```
echo "* * * * * www-data cd /var/www/seslabs/server && php artisan schedule:run >> /dev/null 2>&1" | sudo tee /etc/cron.d/seslabs
```

**Why:** every minute it sends changes to online stores, syncs branch servers, posts to the accounts, sends reminders and, once a day, checks the licence.

**Live updates:** prices and stock reach tills within seconds, instead of within 60 seconds.

1. In `.env` set (three random values of your own, letters and digits, at least 20 characters each; your address or domain as `REVERB_HOST`):

   ```
   REVERB_APP_ID=<random-1>
   REVERB_APP_KEY=<random-2>
   REVERB_APP_SECRET=<random-3>
   REVERB_HOST=192.168.1.20
   REVERB_PORT=443
   REVERB_SCHEME=https
   REVERB_PUBLISH_HOST=127.0.0.1
   REVERB_PUBLISH_PORT=8080
   REVERB_PUBLISH_SCHEME=http
   ```

   **Why:** tills connect through the https address. The server itself sends to the live-updates service directly on the same machine.

2. Create the service:

   ```
   sudo nano /etc/systemd/system/seslabs-reverb.service
   ```

   ```
   [Unit]
   Description=SES Labs live updates
   After=network.target

   [Service]
   User=www-data
   WorkingDirectory=/var/www/seslabs/server
   ExecStart=/usr/bin/php artisan reverb:start --host=127.0.0.1 --port=8080
   Restart=always
   RestartSec=5

   [Install]
   WantedBy=multi-user.target
   ```

3. Start it:

   ```
   cd /var/www/seslabs/server && php artisan optimize
   sudo systemctl daemon-reload
   sudo systemctl enable --now seslabs-reverb
   ```

**Check:** `systemctl status seslabs-reverb` shows *active (running)*.

## 5.10 Next steps

Continue with the licence (**Chapter 6**), the first-time set-up (**Chapter 7**), devices (**Chapter 9**) and backups (**Chapter 12**).
