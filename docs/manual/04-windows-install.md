# 4. Installing the server on Windows (setups A, B and branch servers)

Follow every step in order. Allow about two hours the first time. You need an internet connection during installation.

In this chapter the software goes in `C:\SESLabs` and PHP in `C:\php`. If you choose other folders, change them in every command.

## 4.0 The quick way: the installer (recommended)

SES Labs gives you **`SESLabs-server-setup-<version>.exe`** with your release. It does everything in 4.2–4.9 for you.

1. Do 4.1 (prepare the computer) first.
2. Double-click the setup file, click **Yes** when Windows asks for permission (it must install a database and services).
3. Choose **Setup A** (this PC is also the till) or **Setup B** (shop server for tills on the network). Leave the server id as **HQ** unless SyncLytics gave you a branch server id (Chapter 10).
4. **Reached from the internet?** Leave both boxes empty for a server used only in the shop (the usual case). Only if this server has an internet name pointing to it (for example `shop.example.com`, with ports 80 and 443 forwarded to it by your router), type that name and an e-mail address.
   **Why:** with an internet name, a free certificate from Let's Encrypt is fetched and renewed automatically. Without one, the server makes its own certificate for the shop network (4.9).
5. A blue window shows each step. It downloads PostgreSQL, PHP, Composer and the **Caddy** web server from their official sites (about 450 MB; keep the internet on) and checks each download against its published checksum. It then creates the database with its own random password, the settings, the keys, the tables, and the start-up tasks.
6. At the end it shows the **backup passphrase** — write it on paper and keep it away from the server (Chapter 12) — and asks for your company name and the owner's account.

**Why:** the installer follows exactly the manual steps below, so you can still read them to understand what was done, or do them by hand on an unusual PC.

**Note:** the passwords the installer created (database) are in `C:\SESLabs\installer-secrets.txt`, readable by administrators only. Running the installer again (a newer version) updates the software and keeps your data, settings and keys.

**Advanced:** the same steps without the setup file: `powershell -ExecutionPolicy Bypass -File install.ps1 -Release <release.zip> -Setup B` (as administrator).

The server is then reached over **https** only: `https://localhost/admin` (setup A), or `https://<server-address>/admin` (setup B, for example `https://192.168.1.10/admin`). For setup B, every till, tablet and other PC must trust the server once: see **4.9**.

If you used the installer, do 4.9 (setup B), then continue with Chapter 6 (licence). The rest of this chapter is the manual way.

## 4.1 Prepare the computer

1. **Install all Windows updates** (Start → Settings → Windows Update), restart, and repeat until none are left.
   **Why:** updates fix security holes and avoid restarts in the middle of trading later.
2. **Set the clock to update automatically:** Settings → Time & language → Date & time → switch on *Set time automatically* and *Set time zone automatically* (or choose *(UTC+00:00) Monrovia, Reykjavik* for Ghana).
   **Why:** receipts, shifts and sync depend on the correct time. Tills more than 10 minutes ahead of the server are refused.
3. **Stop the PC from sleeping:** Settings → System → Power → *Screen and sleep* → set *When plugged in, put my device to sleep after* to **Never**.
   **Why:** a sleeping server cannot answer the tills.
4. **Setup B only — give the PC a fixed address on the network.** In your router's settings, find *DHCP reservation* (sometimes *Address reservation* or *Static lease*) and reserve an address for this PC, for example `192.168.1.10`. Ask whoever installed your router if unsure. Write the address down.
   **Why:** tills find the server by its address. If the address changes after a restart, the tills cannot connect.

**Check:** open PowerShell and type `ipconfig`. The *IPv4 Address* of your network adapter shows the address (for setup B it must be the reserved one).

## 4.2 Install PostgreSQL (the database)

1. Download the **PostgreSQL 17 Windows installer** from `https://www.postgresql.org/download/windows/` (the *Download the installer* link goes to EDB).
2. Run it and accept the defaults, with these choices:
   - **Components:** keep *PostgreSQL Server* and *Command Line Tools*. *pgAdmin 4* is optional (a graphical tool). Untick *Stack Builder*.
   - **Password:** type a strong password for the *postgres* administrator account. **Write it down** in your safe place.
   - **Port:** keep `5432`.
   - **Locale:** keep *Default locale*.
3. Finish. PostgreSQL now runs as a Windows service and starts with the computer.

**Why:** PostgreSQL is where every sale, product and account is stored. It is designed never to lose committed data, even in a power cut.

Now create the database and its own user. Open **PowerShell** and type (replace `<db-password>` with a new long random password, different from the *postgres* one, and write it down):

```
& "C:\Program Files\PostgreSQL\17\bin\psql.exe" -U postgres -c "CREATE ROLE erp LOGIN PASSWORD '<db-password>';"
& "C:\Program Files\PostgreSQL\17\bin\psql.exe" -U postgres -c "CREATE DATABASE erp OWNER erp ENCODING 'UTF8';"
```

Each command asks for the *postgres* password you chose in step 2.

**Why:** the software uses its own database user (`erp`) that can only reach its own database, not the administrator account.

**Check:** both commands answer `CREATE ROLE` and `CREATE DATABASE`.

## 4.3 Install PHP

1. Install the **Microsoft Visual C++ Redistributable (x64)** for Visual Studio 2015–2022 from Microsoft's website (`https://aka.ms/vs/17/release/vc_redist.x64.exe`). **Why:** PHP for Windows is built with it and will not start without it.
2. Go to `https://windows.php.net/download/` and download **PHP 8.4, VS17 x64 Non Thread Safe, Zip**.
3. Create the folder `C:\php` and extract the ZIP **into** it, so that `C:\php\php.exe` exists.
4. In `C:\php`, copy `php.ini-production` and rename the copy to `php.ini`.
5. Open `php.ini` in Notepad and change these lines (search with Ctrl+F; remove the `;` at the start of a line to switch it on):

```
extension_dir = "ext"
extension=curl
extension=fileinfo
extension=gd
extension=intl
extension=mbstring
extension=openssl
extension=pdo_pgsql
extension=pgsql
extension=sodium
extension=zip
memory_limit = 512M
upload_max_filesize = 20M
post_max_size = 25M
date.timezone = Africa/Accra
upload_tmp_dir = "C:\SESLabs\tmp"
sys_temp_dir = "C:\SESLabs\tmp"
```

   Create the folder `C:\SESLabs\tmp` (it holds files while they are being uploaded, e.g. CSV imports).

   **Why:** these extensions let PHP talk to PostgreSQL (`pdo_pgsql`), handle money and text in every language (`bcmath`, which is built in, `intl`, `mbstring`), and protect secrets (`sodium`, `openssl`).
6. Add PHP to the system path, so Windows finds `php` from any folder: Start → type `environment` → **Edit the system environment variables** → **Environment Variables…** → under *System variables* select **Path** → **Edit** → **New** → type `C:\php` → **OK** three times.
7. Close PowerShell and open it again (it reads the new path only when it starts).

**Check:** type `php --version`. It shows `PHP 8.4…`. Then type `php -m` and make sure the list contains `bcmath`, `intl`, `pdo_pgsql`, `pgsql` and `sodium`.

## 4.4 Install Composer and Node.js

1. Download and run **Composer-Setup.exe** from `https://getcomposer.org/download/`. When it asks for PHP, choose `C:\php\php.exe`. Leave the proxy empty.
2. Download and run the **Node.js LTS** Windows installer (`.msi`) from `https://nodejs.org/`. Accept the defaults. You do not need the *Tools for native modules* option.
3. Close PowerShell and open it again.

**Check:** `composer --version` shows `Composer version 2…`, and `node --version` shows `v22…` or later.

**Why:** Composer downloads the server's building blocks; Node.js builds the till app. Both are only used during installation and updates.

## 4.5 Put the software in place

SES Labs gives you the software as a ZIP file named like `ses-labs-<your-company>-<build>.zip`.

1. Create the folder `C:\SESLabs`.
2. Extract the ZIP into it, so that `C:\SESLabs\server\artisan` exists.

**Note:** If you were given the **source code** (for example through Git) instead of a release ZIP, place it so that `C:\SESLabs\server` and `C:\SESLabs\pos` exist, and also do step 4.7 (*Build the till app*).

## 4.6 Configure the server

Open PowerShell and go to the server folder:

```
cd C:\SESLabs\server
```

1. **Download the server's libraries:**

   ```
   composer install --no-dev --optimize-autoloader
   ```

   **Why:** the release contains only SES Labs's own code; this downloads the open-source libraries it builds on, at the exact versions it was tested with. `--no-dev` leaves out testing tools.

2. **Create the settings file** from the example:

   ```
   copy .env.example .env
   notepad .env
   ```

   Change these lines and save:

   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://<server-address>
   SESSION_SECURE_COOKIE=true
   DB_PASSWORD=<db-password>
   ERP_NODE_ROLE=hq
   ERP_NODE_ID=HQ
   ```

   - `<server-address>`: `localhost` for setup A; the fixed address from 4.1 (e.g. `192.168.1.10`) for setup B.
   - For a **branch server**, use `ERP_NODE_ROLE=branch` and the branch server's id (e.g. `B01`) as given in Chapter 10.

   **Why:** `.env` tells the software where its database is, what address it is reached on, and that it is a live system (`APP_DEBUG=false` hides technical error details from users). `SESSION_SECURE_COOKIE=true` makes browsers send the sign-in cookie only over https.

   **Warning:** `.env` will hold your secret keys. Never e-mail it, never put it on a shared drive, and include it in your backups (Chapter 12).

3. **Create the secret keys:**

   ```
   php artisan key:generate
   php artisan erp:audit-key
   ```

   **Why:** `key:generate` creates `APP_KEY`, which encrypts sessions and stored secrets. `erp:audit-key` creates `ERP_AUDIT_KEY`, which seals the audit log so changes to history can be detected. **Never change either key after this.**

4. **Create the database tables and the standard data** (roles, permissions, units, tax templates, chart of accounts):

   ```
   php artisan migrate --seed --force
   ```

   **Why:** this builds the empty structure of the database. `--force` confirms you are on a live system.

   **Check:** it ends with lines saying `DONE` and no red errors.

5. **Create your company and the owner account:**

   ```
   php artisan erp:install
   ```

   It asks for the company name, the owner's full name, a username (for till sign-in), email and a password of at least 12 characters.

   **Why:** the owner is the first person who can sign in; they create everyone else.

   **Note:** Skip this step on a **branch server**: its company and people come from head office (Chapter 10).

6. **Build the files for the online shop's live updates** (optional, but harmless):

   ```
   npm install
   npm run build
   ```

7. **Speed up the server** (run again after every change to `.env`):

   ```
   php artisan optimize
   ```

## 4.7 Build the till app (source code only)

Skip this if your release ZIP already contains `C:\SESLabs\server\public\pos\index.html`.

```
cd C:\SESLabs\pos
npm install
npm run build:server
```

**Why:** this turns the till's source code into the app the server hands out at `/pos/`, including the offline files that let tills keep selling without the server.

**Check:** the file `C:\SESLabs\server\public\pos\index.html` now exists.

## 4.8 Start the server automatically

The server needs these programs running all the time:

- the **web server (Caddy)**: the only program the tills and browsers talk to. It serves everything over **https**, so passwords, PINs and sales cannot be read by anyone else on the network;
- **PHP workers**: they run SES Labs itself, behind the web server. Each one serves one request at a time, so there are four;
- the **scheduler**: it runs the routine jobs every minute (sending to online stores, syncing branches, posting to the accounts, reminders, the daily licence check-in, the nightly insights and alerts);
- **live updates (Reverb)**: they tell tills about price and stock changes within seconds. They are reached through the web server, so no extra port is open.

We register them with Windows **Task Scheduler**, so they start with the computer even when nobody is signed in, and restart if they stop.

**Step 1: get Caddy.** Download `caddy_2.10.0_windows_amd64.zip` from the official releases page `https://github.com/caddyserver/caddy/releases` and unzip it to `C:\SESLabs\caddy`.

**Why:** Caddy is a widely used web server that gets and renews https certificates by itself.

**Step 2: protect its data folder.** In PowerShell as administrator:

```
New-Item -ItemType Directory -Force C:\SESLabs\caddy\data
icacls C:\SESLabs\caddy\data /inheritance:r /grant:r "Administrators:(OI)(CI)F" "SYSTEM:(OI)(CI)F"
```

**Why:** on the shop network Caddy acts as its own small certificate authority, and its secret key lives in this folder. Only administrators may read it.

**Step 3: the settings file.** Create `C:\SESLabs\Caddyfile` with Notepad. This example is for setup B with address `192.168.1.10` and computer name `shopserver`; change both. For setup A, use `localhost` as the address, remove the whole `http://…` block, and add the line `default_bind 127.0.0.1` under `admin off`.

```
{
	admin off
	skip_install_trust
	storage file_system "C:/SESLabs/caddy/data"
	pki {
		ca local {
			name "SES Labs on shopserver"
			root_cn "SES Labs shopserver Root"
			intermediate_cn "SES Labs shopserver Intermediate"
		}
	}
}

http://192.168.1.10, http://shopserver {
	handle /ca.crt {
		root * "C:/SESLabs/caddy/data/pki/authorities/local"
		rewrite * /root.crt
		header Content-Type application/x-x509-ca-cert
		file_server
	}
	handle {
		redir https://{host}{uri} permanent
	}
}

192.168.1.10, shopserver {
	tls internal
	root * "C:/SESLabs/server/public"
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
		php_fastcgi 127.0.0.1:9001 127.0.0.1:9002 127.0.0.1:9003 127.0.0.1:9004 {
			lb_policy least_conn
			lb_try_duration 10s
			read_timeout 300s
		}
		file_server
	}
}
```

**Why:**
- *Plain http* only hands out the server's certificate (4.9) and sends everything else to https.
- *Hidden files* such as `.env` are never served.
- *Live updates* use the same https address.
- *The till app* falls back to its own start page, so it works offline.
- *Everything else* goes to the PHP workers.

For a server with an **internet name**:
- remove the `http://…` block and the `tls internal` line;
- write the name instead of the address;
- add `email you@example.com` under `admin off`.

Caddy then gets a Let's Encrypt certificate by itself.

**Check:** `C:\SESLabs\caddy\caddy.exe validate --config C:\SESLabs\Caddyfile --adapter caddyfile` ends with *Valid configuration*.

**Step 4: the PHP worker.** Create `C:\SESLabs\php-worker.cmd`:

```
@echo off
set PHP_FCGI_MAX_REQUESTS=0
:run
"C:\php\php-cgi.exe" -b 127.0.0.1:%1
ping -n 3 127.0.0.1 >nul
goto run
```

**Why:** PHP on Windows stops a worker after 500 requests unless told otherwise. The loop restarts it if it ever stops.

**Step 5: in `.env`,** add or change these lines (with your address), then run `php artisan optimize`:

```
REVERB_APP_ID=<random-1>
REVERB_APP_KEY=<random-2>
REVERB_APP_SECRET=<random-3>
REVERB_HOST=192.168.1.10
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_PUBLISH_HOST=127.0.0.1
REVERB_PUBLISH_PORT=8080
REVERB_PUBLISH_SCHEME=http
```

Use three random values of your own (letters and digits, at least 20 characters each).

**Why:** tills connect to live updates through the https address. The server itself sends to them directly on the same PC.

**Step 6: the start-up tasks.** Open **PowerShell as administrator** and paste this whole block:

```
$app = "C:\SESLabs\server"; $php = "C:\php\php.exe"
$settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest
$trigger = New-ScheduledTaskTrigger -AtStartup
Register-ScheduledTask -TaskName "SES Labs Scheduler" -Trigger $trigger -Settings $settings -Principal $principal -Action (New-ScheduledTaskAction -Execute $php -Argument "artisan schedule:work" -WorkingDirectory $app)
Register-ScheduledTask -TaskName "SES Labs Live" -Trigger $trigger -Settings $settings -Principal $principal -Action (New-ScheduledTaskAction -Execute $php -Argument "artisan reverb:start --host=127.0.0.1 --port=8080" -WorkingDirectory $app)
foreach ($i in 1..4) { Register-ScheduledTask -TaskName "SES Labs PHP $i" -Trigger $trigger -Settings $settings -Principal $principal -Action (New-ScheduledTaskAction -Execute "cmd.exe" -Argument "/c `"`"C:\SESLabs\php-worker.cmd`" $(9000 + $i)`"" -WorkingDirectory $app) }
Register-ScheduledTask -TaskName "SES Labs Web" -Trigger $trigger -Settings $settings -Principal $principal -Action (New-ScheduledTaskAction -Execute "C:\SESLabs\caddy\caddy.exe" -Argument "run --config C:\SESLabs\Caddyfile --adapter caddyfile" -WorkingDirectory "C:\SESLabs\caddy")
Get-ScheduledTask -TaskName "SES Labs *" | Start-ScheduledTask
```

**Why:** `ExecutionTimeLimit 0` stops Windows from ending the tasks after three days (its default). `SYSTEM` runs them without anyone signed in.

**Step 7: trust the server on this PC.** Wait 10 seconds, then:

```
Import-Certificate -FilePath C:\SESLabs\caddy\data\pki\authorities\local\root.crt -CertStoreLocation Cert:\LocalMachine\Root
```

**Why:** the server's own browser then accepts its certificate (other devices: 4.9). Skip this step for a server with an internet name.

**Step 8 (setup B only): let the tills through the Windows firewall:**

```
New-NetFirewallRule -DisplayName "SES Labs server" -Direction Inbound -Protocol TCP -LocalPort 80,443 -Action Allow -Profile Private,Domain
```

**Why:** Windows blocks incoming connections by default. This opens only the web server's two ports, and only on private (shop) networks, not public Wi-Fi. The database, the PHP workers and live updates stay reachable from this PC only.

**Check:**
1. In a browser on the server, open `https://localhost/up` (setup A) or `https://<server-address>/up` (setup B). It shows a page saying *Application up*, with no certificate warning.
2. Open `…/admin`: the sign-in page appears:

   ![The back-office sign-in page](img/admin-login.png)

3. Setup B: on another PC that trusts the server (4.9), open `https://<server-address>/admin`. The same page appears.

**Tip:** to restart the server, open **Task Scheduler**, click *Task Scheduler Library*, right-click *SES Labs Web* (or a *PHP*, *Scheduler* or *Live* task), and choose **End**, then **Run**.

## 4.9 Let tills and other PCs trust this server (servers without an internet name)

On the shop network (and on an Ubuntu server reached by its address, Chapter 5) the server uses **its own certificate**. A certificate is what proves to a browser that it is really talking to your server and that nobody can read along. Each till, tablet, handheld, kitchen screen and office PC must be told **once** to trust it. Without this, browsers show a warning, and the till refuses to enrol: it only uses its security functions on a trusted address.

**On a Windows PC (also for the SES Labs Till desktop app):** open PowerShell **as administrator** and type, with your server's address:

```
Invoke-WebRequest http://192.168.1.10/ca.crt -OutFile $env:TEMP\seslabs.crt
Import-Certificate -FilePath $env:TEMP\seslabs.crt -CertStoreLocation Cert:\LocalMachine\Root
```

**On an Android tablet or phone:**
1. In Chrome, open `http://<server-address>/ca.crt`. It downloads a file.
2. Open **Settings → Security (and privacy) → More security settings → Encryption & credentials → Install a certificate → CA certificate**, confirm with **Install anyway**, and choose the downloaded file. The wording differs a little between makes.

**Check:** open `https://<server-address>/pos/`. It opens with a padlock and no warning.

**Why this is safe:** the certificate is made by your own server, for your own server. Its secret key never leaves the server and is readable by administrators only.

**Note:**
- The certificate is valid for the server's address and computer name at the time of installation. If you ever give the server a different address, run the installer again, then repeat this step on each device.
- A server with an internet name uses a public Let's Encrypt certificate instead, which every device already trusts. Skip this section.
- Live updates (instant price and stock changes on tills) are set up by the installer and need nothing more.

## 4.10 Next steps

- Activate the licence: **Chapter 6**.
- First-time set-up in the back office: **Chapter 7**.
- Set up the tills: **Chapter 9**.
- Set up backups **before** you go live: **Chapter 12**.
