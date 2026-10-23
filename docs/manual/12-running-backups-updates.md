# 12. Daily running, backups, updates and moving

## 12.1 Every day

| Who | What | Where |
|---|---|---|
| Cashiers | Open the shift with the float; close it with the cash count | Till |
| Supervisor | Look at the **Review queue** and resolve items | Sales → Review queue |
| Manager | Check no till shows a red banner (*refused*) or a long yellow queue | Tills; System → Devices (*Last seen*) |
| IT / owner | Check **System → Health** is all green (backup made last night, scheduler running) | System → Health |
| Head office | Check branch servers synced recently (setup E) | System → Sync Center |
| Owner or branch manager | **Close the day** before leaving | Cash → Close the day |

**Close the day** (Cash → *Close the day*; owner, general manager and branch manager): one screen per branch shows whether the day is wrapped up — ✓ every till closed and counted, the cash counted and any shortage by cashier, cash handed over and banked (when you use cash control), every sale received from the tills, medicine sales signed off (pharmacies), approvals waiting, and last night's backup. Enter the cash **taken to the bank** with the **deposit slip number**, and what was **kept in the safe**, then **Close the day**. A red ✗ (e.g. a till still open) needs a sentence saying why before the day can be closed; amber ! items are reminders. Closing again later (after the till was closed) updates the record. **Recent days** lists the last two weeks: who closed, cash counted, over / short, banked, slip, safe. The daily summary says, per branch, who closed the day or *not yet*. **Why:** one look tells the owner the day is finished and the money is where it should be; a missing deposit or an open till is noticed the same evening, not at the month-end.

**Every month:** verify the audit log (**System → Audit log → Verify chain**), check stock (`php artisan erp:stock-check`), take the USB backup drive home if you use one, and every three months practise a full restore on a spare PC (12.3).

**You are told when something is wrong.** Every hour the server checks itself; when a check turns red — a backup failed or is more than 26 hours old, the weekly restore check failed, the backup passphrase is missing, the disk or memory is nearly full, the server is overloaded, the licence is read-only — everyone who looks after the system settings (owner, IT Admin) gets a notification on the bell and an e-mail, once a day per problem, until it is fixed. **System → Health** also shows the server's **load** and **memory** (on Linux servers). **Why:** a backup that quietly stopped is only found when it is needed; now it is found the next morning.

**The daily summary** (System → Settings → *Daily summary*, off until you switch it on): each evening at the time you choose (default 20:00), the owner and general manager (anyone with *receive the daily summary*) get the day in one message — sales by branch against the same day last week, cash / mobile money / card, cash over or short at the till closes, low, expired and expiring stock, today's losses and unusual ones, and what is waiting for approval. On the bell, by e-mail (on by default) and, if WhatsApp Business is set up, a short WhatsApp. **Why:** the owner sees the whole business in one minute, wherever they are.

**The SyncLytics support account** can be switched off by the company: **System → Settings → Security → The SyncLytics support account may sign in**. Switch it back on when you want support to look at something.

### Who did what, and from which computer

**System → Audit log** lists every change and every sign-in: who, when, what changed, and where from.

| Column | What it shows |
|---|---|
| **IP address** | The address the server saw. For a cloud server it is the shop's internet router, the same for every PC in that shop. For a server in the shop it is the PC's own address. |
| **Computer** | The name you gave the computer (see below), the browser and system (e.g. *Edge 141 on Windows*), and for the **desktop till app** (version 0.25 or later) also the **computer name**, its **MAC address** (network card) and its **address on the shop network**. |

Open a row for the details; click the IP address to see roughly where it is (town and internet provider, on ipinfo.io). The **Computer** filter shows everything one computer did; the **Sign-ins** filter shows back-office sign-ins, sign-outs and failed attempts, and till sign-ins.

**Name your computers: System → Computers.** Every computer is listed the first time someone signs in to the back office from it, with who used it last, when and from which address. Click **Name it** and type a name you will recognise, such as *Front desk PC* or *Manager's laptop*. From then on the audit log shows that name. **What it did** opens its audit log. A computer you do not recognise means someone signed in from a computer you did not expect: change that person's password.

**Renamed accounts:** records keep the name a person had when they did something, so renaming an account never rewrites history. Where the account has been renamed since, a small ⇄ icon shows beside the name; hover over it for today's name. **People & access → Users → the person → Name history** lists every change of name and username, when and by whom. Give every person their own account; do not rename one person's account for someone else, or their work will look like the new person's.

**Why browsers show less:** for your security, a web browser never tells a website the computer's name or MAC address. So the system gives each browser its own lasting ID instead (kept like a cookie). Clearing the browser's data or using another browser makes it a new, unnamed computer in the list. The desktop till app does report the computer's name and MAC address, also when the back office is opened in it (on its set-up page: **Open the back office**). These are what the computer says about itself: good for telling computers apart, not proof that nobody pretended.

## 12.2 Backups (automatic, encrypted)

**Why:** a theft, fire, failed disk or ransomware attack can destroy the server. A backup kept **somewhere else** is the only way to get your sales, stock and accounts back.

The server makes a backup **every night at 02:00** by itself. Each backup holds everything needed to start again: the **database**, the **`.env` settings** (with the keys that unlock stored secrets and seal the audit log) and the **uploaded files**. It is **encrypted** with your backup passphrase, so a lost USB drive or a leaked cloud folder reveals nothing. Every Sunday the newest backup is **checked**: decrypted, read, and — where the database user may create a scratch database — fully restored into it and compared, without touching your live data.

### The backup passphrase

The installers create it and show it once. Otherwise, in the `server` folder run `php artisan erp:backup:key`.

**Warning:** write the passphrase on paper and keep it **away from the server** (with the owner, or in a safe). Without it no backup can be restored.

### Where backups go

The server keeps the last 7 itself. Add at least one place **outside the server** under **System → Backups → Add destination** (a test file is written and removed before it is saved):

| Destination | What to fill in | Good for |
|---|---|---|
| **Folder** — USB drive, second disk, network share | The folder, e.g. `E:\Backups` (Windows) or `/mnt/usb/backups` | Shops: rotate two USB drives, one always at home |
| **S3-compatible storage** — Backblaze B2, Wasabi, AWS | Endpoint, region, bucket, access key and secret (from your storage account; give the key access to that bucket only) | Cloud copies at low cost |
| **Google Drive** | OAuth client ID and secret, a refresh token, and the Drive folder ID (from a Google Cloud project in **your** Google account — your IT person sets this up once) | Businesses already on Google Workspace |
| **Another server (SFTP)** | Address, port, user, password or private key, folder | Head office or a hosting account |

**Keep the newest …** says how many backups each destination keeps; older ones there are deleted automatically.

![System → Backups](img/backups.png)

**Check:** **Back up now**, then the backup shows ✓ for each destination, and **Check the newest backup** reports *Readable* or *Restore drill passed*. **System → Health** then shows *Backups OK*.

**Note:** on Windows set `BACKUP_PG_BIN=C:\SESLabs\pgsql\bin` (the installer does this). For the full restore drill the database user needs permission to create a scratch database: the installers give it; on an existing server an administrator runs `ALTER ROLE erp CREATEDB;` in PostgreSQL.

## 12.3 Restoring from a backup

Use this after a disaster, or to practise on a spare PC.

1. Install the server software on the new machine with the installer or script (Chapter 4 or 5) — it creates an empty company you will replace.
2. Copy the newest `.synbak` backup file to it (from the USB drive, cloud storage or **System → Backups → Download** on the old server if it still works).
3. Put your **backup passphrase** in the new server's `.env` as `BACKUP_PASSPHRASE=…`, then in the `server` folder:
   ```
   php artisan erp:backup:restore C:\path\ses-labs-hq-20261029-020000.synbak
   ```
   It checks the backup, asks for confirmation, and replaces the data.
4. The backup's settings are saved next to `.env` as `.env.from-backup`. Copy `APP_KEY` and `ERP_AUDIT_KEY` from it into `.env` (they must match the backup), then `php artisan optimize`.
5. Restart the server (Task Scheduler on Windows, or reboot).

**Check:** `php artisan erp:audit-verify` reports the chain intact, `php artisan erp:stock-check` passes, and yesterday's sales are in **Sales → Till sales**. If this is a new machine, re-activate the licence (Chapter 6.6).

**Note:** tills keep any sales made since the backup in their queue and send them when the server is back, so they are not lost.

## 12.4 Updating the software

SES Labs sends you a new release. Update outside trading hours.

**The simple way (recommended):**
- **Windows:** run the new `SESLabs-server-setup-<version>.exe` on the server and choose the same folder.
- **Ubuntu:** `sudo bash install.sh --zip <new release>.zip` with the same options as the first time.

Either one **takes a backup first** with the version still installed. If that backup fails, it stops and changes nothing. Then it replaces the software, keeps your `.env`, uploads, backups and keys, updates the database, prepares the caches, applies the speed settings and restarts the services. Tills update by themselves (step 6 below).

**Why the backup first:** if anything goes wrong during the update, that backup puts the business back exactly as it was.

**By hand** (only if SyncLytics support asks you to):

1. **Take a backup** (run the backup task now) and check it.
2. Stop the server: Windows — Task Scheduler → *End* both SES Labs tasks; Ubuntu — `sudo systemctl stop caddy`.
3. Extract the new ZIP **over** the existing folder (`C:\SESLabs` or `/var/www/seslabs`). Your `.env` and `storage` are kept.
4. In the `server` folder:

   ```
   composer install --no-dev --optimize-autoloader
   php artisan migrate --seed --force
   php artisan optimize
   php artisan filament:optimize
   ```

   **Why the last two:** they prepare the settings, routes, screens and icons once, so pages do not rebuild them on every request.

   (Ubuntu: then `sudo chown -R www-data:www-data storage bootstrap/cache`.)
   If you install from source code, also rebuild the till: in `pos`, `npm install` and `npm run build:server`.
5. Start the server again (Windows — *Run* both tasks; Ubuntu — `sudo systemctl start caddy` and `sudo systemctl restart php8.4-fpm`).
6. **Tills** download the new version by themselves. It takes effect when the till app has been **closed completely and opened again** (do this at the end of the day; sometimes it needs two restarts).

**Updating to 0.34.2 or later from an older version:** use the simple way above. It switches on PHP's code cache (OPcache) and other speed settings, which older Windows installations did not have, and pages then open several times faster. *System → Health* shows *PHP code cache: On* afterwards. The first update to 0.34.2 also adds database indexes; on a large database this takes a few minutes, so let it finish.

**Optional on older installations:** in `server\.env` set `LOG_LEVEL=warning` (the log no longer records every detail) and `CACHE_STORE=file` (one less database round-trip for each till request), then run `php artisan optimize` again. New installations have both already.

**Why `migrate --seed`:** it adds new tables and new permissions safely; it never removes your data or changes your own settings.

**With branch servers:** update **head office first**, then each branch server.

## 12.5 Moving to a new server

1. Install the new server (Chapter 4 or 5) without creating keys, tables or the company, as in 12.3 step 1.
2. Take a fresh backup on the old server **after closing trading** and stop the old server.
3. Restore it on the new server (12.3).
4. Point the devices to the new address: if the address changed, open the new till address on each device and enrol it again (System → Devices → New code), after its queue has been sent.
5. Re-activate the licence within 14 days (Chapter 6.6).

## 12.6 Trying changes on the build PC (preview)

To look at a new version before it goes to a client, on the PC with the source code:

```
node pos/scripts/preview.mjs
```

It builds the till into the server, brings the local database up to date, and starts the server: the back office at **http://127.0.0.1:8010/admin** and the till at **http://127.0.0.1:8010/pos/** — one address, nothing else to set up. In the Claude app, the same is the **ERP preview** entry in the browser pane. Stop it with **Ctrl+C**. **Why:** one command shows exactly what the client will get, without the separate development server on port 5173.

