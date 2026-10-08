# 5. Installing the server on Ubuntu Linux (setups B, C, D and branch servers)

Ubuntu is the recommended system for a head-office, central or cloud server, and for any shop server with more than four devices. It runs for months without restarts and handles many tills at once.

This chapter assumes **Ubuntu Server 24.04 LTS**, freshly installed, on a cloud server (VPS) or a computer at your premises. You run every command as a user that may use `sudo` (the cloud provider's default user, often `ubuntu` or `root`).

## 5.1 Get the server and connect to it

### Cloud server (setup D)

1. Rent a VPS from any provider (for example a provider with a data centre in or near West Africa or Europe). Choose **Ubuntu 24.04**, at least 2 vCPU, 4 GB RAM, 80 GB SSD. Note the server's **public IP address** and the password or key you are given.
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

## 5.4 Install PHP 8.4, Nginx, Composer and Node.js

```
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y nginx unzip git php8.4-fpm php8.4-cli php8.4-pgsql php8.4-intl php8.4-bcmath php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-gd
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

**Why:** PHP runs the software; **PHP-FPM** lets several requests run at the same time; **Nginx** is the web server that receives the tills' and browsers' requests; Composer and Node.js are used during installation.

**Check:** `php --version` shows 8.4, `composer --version` shows 2.x, `node --version` shows v22 or later, and `php -m` lists `pdo_pgsql`, `intl`, `bcmath` and `sodium`.

## 5.5 Put the software in place

1. Copy your release ZIP to the server (with WinSCP, into your home folder).
2. Unpack it into `/var/www/synclytics`:

```
sudo mkdir -p /var/www/synclytics
sudo unzip ~/synclytics-*.zip -d /var/www/synclytics
sudo chown -R $USER:www-data /var/www/synclytics
cd /var/www/synclytics/server
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
DB_PASSWORD=<db-password>
ERP_NODE_ROLE=hq
ERP_NODE_ID=HQ
```

- For a server reached by IP on your network without HTTPS, use `APP_URL=http://<server-ip-address>`.
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
cd /var/www/synclytics/pos && npm install && npm run build:server && cd ../server
```

## 5.7 Configure Nginx

Create the site file:

```
sudo nano /etc/nginx/sites-available/synclytics
```

Paste this, replacing `erp.yourshop.com` with your domain (or the server's IP address):

```
server {
    listen 80;
    server_name erp.yourshop.com;
    root /var/www/synclytics/server/public;
    index index.php index.html;
    client_max_body_size 25M;

    # The till app: its own files, and index.html for anything else under /pos/
    location /pos/ {
        try_files $uri $uri/ /pos/index.html;
    }
    location = /pos/sw.js { add_header Cache-Control "no-cache"; }
    location = /pos/index.html { add_header Cache-Control "no-cache"; }

    # Live updates (only used if you set up Reverb, section 5.9)
    location /app/ {
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
        proxy_pass http://127.0.0.1:8080;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

Switch it on:

```
sudo ln -s /etc/nginx/sites-available/synclytics /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

**Why:** Nginx hands out the till app's files directly and passes everything else to PHP. `no-cache` on the till's start files makes tills pick up updates promptly. The last rule hides private files such as `.env`.

**Check:** `nginx -t` says *syntax is ok*, and `http://erp.yourshop.com/admin` (or `http://<ip>/admin`) shows the sign-in page.

## 5.8 Firewall and HTTPS

```
sudo ufw allow OpenSSH
sudo ufw allow "Nginx Full"
sudo ufw enable
```

**Why:** the firewall blocks everything except remote administration (SSH) and the web (ports 80 and 443).

For a server with a domain name (setup D, and any server reached over the internet), get a free certificate:

```
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d erp.yourshop.com
```

Answer the questions (your e-mail, accept the terms, choose to **redirect** HTTP to HTTPS). Certbot renews the certificate automatically.

**Why:** HTTPS encrypts everything between tills and the server (PINs, sales, customer details). Browsers also only allow tills to install as apps and work offline from HTTPS addresses (or from `localhost`).

**Check:** `https://erp.yourshop.com/admin` shows the padlock in the browser.

**Warning:** Never run a server that is reachable from the internet without HTTPS.

## 5.9 Background jobs

**The scheduler** runs the routine jobs. Add it to the system's timer (cron):

```
echo "* * * * * www-data cd /var/www/synclytics/server && php artisan schedule:run >> /dev/null 2>&1" | sudo tee /etc/cron.d/synclytics
```

**Why:** every minute it sends changes to online stores, syncs branch servers, posts to the accounts, sends reminders and, once a day, checks the licence.

**Live updates (optional)** — prices and stock reach tills within seconds instead of up to 60 seconds:

1. In `.env` set:

   ```
   REVERB_APP_ID=<random-1>
   REVERB_APP_KEY=<random-2>
   REVERB_APP_SECRET=<random-3>
   REVERB_HOST=erp.yourshop.com
   REVERB_PORT=443
   REVERB_SCHEME=https
   ```

   (Without HTTPS: `REVERB_PORT=80` and `REVERB_SCHEME=http`.)

2. Create the service:

   ```
   sudo nano /etc/systemd/system/synclytics-live.service
   ```

   ```
   [Unit]
   Description=Synclytics live updates
   After=network.target

   [Service]
   User=www-data
   WorkingDirectory=/var/www/synclytics/server
   ExecStart=/usr/bin/php artisan reverb:start --host=127.0.0.1 --port=8080
   Restart=always
   RestartSec=5

   [Install]
   WantedBy=multi-user.target
   ```

3. Start it:

   ```
   cd /var/www/synclytics/server && php artisan optimize
   sudo systemctl daemon-reload
   sudo systemctl enable --now synclytics-live
   ```

**Check:** `systemctl status synclytics-live` shows *active (running)*.

## 5.10 Next steps

Continue with the licence (**Chapter 6**), the first-time set-up (**Chapter 7**), devices (**Chapter 9**) and backups (**Chapter 12**).
