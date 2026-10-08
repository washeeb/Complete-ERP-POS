# 12. Daily running, backups, updates and moving

## 12.1 Every day

| Who | What | Where |
|---|---|---|
| Cashiers | Open the shift with the float; close it with the cash count | Till |
| Supervisor | Look at the **Review queue** and resolve items | Sales → Review queue |
| Manager | Check no till shows a red banner (*refused*) or a long yellow queue | Tills; System → Devices (*Last seen*) |
| IT / owner | Check yesterday's **backup** exists (12.2) | Backup folder |
| Head office | Check branch servers synced recently (setup E) | System → Sync Center |

**Every month:** verify the audit log (**System → Audit log → Verify chain**), check stock (`php artisan erp:stock-check`), and practise a restore (12.3) every three months.

## 12.2 Backups

**Why:** a theft, fire, failed disk or ransomware attack can destroy the server. A backup kept **somewhere else** is the only way to get your sales, stock and accounts back.

A complete backup has three parts:

1. the **database** (all your data);
2. the **`.env` file** (holds `APP_KEY` and `ERP_AUDIT_KEY` — without them some stored secrets cannot be read and the audit log cannot be verified);
3. **uploaded files** (receipt photos, documents) in `server/storage/app`.

**Warning:** a backup on the same disk as the server is not a backup. Copy it daily to another place: a USB disk you take home, another PC, or cloud storage.

### Windows: automatic daily backup

1. Create the folder for backups, ideally on a second disk or USB drive, e.g. `D:\SynclyticsBackups`.
2. In Notepad create `C:\Synclytics\backup.ps1` with this content (put your database password in the third line):

   ```
   $stamp = Get-Date -Format "yyyy-MM-dd_HHmm"
   $dest = "D:\SynclyticsBackups"
   $env:PGPASSWORD = "<db-password>"
   New-Item -ItemType Directory -Force $dest | Out-Null
   & "C:\Program Files\PostgreSQL\17\bin\pg_dump.exe" -h 127.0.0.1 -U erp -F c -f "$dest\erp_$stamp.dump" erp
   Copy-Item "C:\Synclytics\server\.env" "$dest\env_$stamp.txt"
   Compress-Archive -Path "C:\Synclytics\server\storage\app" -DestinationPath "$dest\files_$stamp.zip" -Force
   Get-ChildItem $dest -File | Where-Object LastWriteTime -lt (Get-Date).AddDays(-30) | Remove-Item
   ```

3. Schedule it every night (PowerShell **as administrator**):

   ```
   Register-ScheduledTask -TaskName "Synclytics Backup" -Trigger (New-ScheduledTaskTrigger -Daily -At 23:30) -Principal (New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest) -Action (New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-NoProfile -ExecutionPolicy Bypass -File C:\Synclytics\backup.ps1")
   Start-ScheduledTask "Synclytics Backup"
   ```

4. Protect the script (it contains the database password): right-click `backup.ps1` → Properties → Security → allow only *Administrators* and *SYSTEM*.

**Check:** after a minute, `D:\SynclyticsBackups` contains an `erp_….dump`, an `env_….txt` and a `files_….zip` file.

### Ubuntu: automatic daily backup

```
sudo mkdir -p /var/backups/synclytics && sudo chown postgres /var/backups/synclytics
sudo nano /etc/cron.d/synclytics-backup
```

Paste (one line per job) and save:

```
30 23 * * * postgres pg_dump -F c -f /var/backups/synclytics/erp_$(date +\%F).dump erp
35 23 * * * root cp /var/www/synclytics/server/.env /var/backups/synclytics/env_$(date +\%F).txt && tar czf /var/backups/synclytics/files_$(date +\%F).tgz -C /var/www/synclytics/server/storage app
0 1 * * * root find /var/backups/synclytics -type f -mtime +30 -delete
```

Then copy `/var/backups/synclytics` to another place every day (another server, or cloud storage with a tool such as `rclone`; your provider's snapshot feature is a good extra).

## 12.3 Restoring from a backup

Use this after a disaster, or to practise on a spare PC.

1. Install the server software exactly as in Chapter 4 or 5, **up to and including** creating the database (4.2 / 5.3), putting the software in place and `composer install`. **Do not** run `key:generate`, `erp:audit-key`, `migrate` or `erp:install`.
2. Put back the `.env` file from the backup (rename `env_….txt` to `.env` in the `server` folder). Change `APP_URL` and `DB_PASSWORD` if they differ on the new machine.
3. Restore the database:
   - Windows: `& "C:\Program Files\PostgreSQL\17\bin\pg_restore.exe" -h 127.0.0.1 -U erp -d erp --no-owner erp_<date>.dump`
   - Ubuntu: `sudo -u postgres pg_restore -d erp --no-owner --role=erp /path/erp_<date>.dump`
4. Restore the uploaded files into `server/storage/app`.
5. In the `server` folder: `php artisan migrate --seed --force` then `php artisan optimize`.
6. Start the server (4.8 / 5.9).

**Check:** `php artisan erp:audit-verify` reports the chain intact, `php artisan erp:stock-check` passes, and yesterday's sales are in **Sales → Till sales**. If this is a new machine, re-activate the licence (Chapter 6.6).

**Note:** tills keep any sales made since the backup in their queue and send them when the server is back, so they are not lost.

## 12.4 Updating the software

Synclytics sends you a new release ZIP. Update outside trading hours.

1. **Take a backup** (run the backup task now) and check it.
2. Stop the server: Windows — Task Scheduler → *End* both Synclytics tasks; Ubuntu — `sudo systemctl stop nginx`.
3. Extract the new ZIP **over** the existing folder (`C:\Synclytics` or `/var/www/synclytics`). Your `.env` and `storage` are kept.
4. In the `server` folder:

   ```
   composer install --no-dev --optimize-autoloader
   php artisan migrate --seed --force
   php artisan optimize
   ```

   (Ubuntu: then `sudo chown -R www-data:www-data storage bootstrap/cache`.)
   If you install from source code, also rebuild the till: in `pos`, `npm install` and `npm run build:server`.
5. Start the server again (Windows — *Run* both tasks; Ubuntu — `sudo systemctl start nginx` and `sudo systemctl restart php8.4-fpm`).
6. **Tills** download the new version by themselves. It takes effect when the till app has been **closed completely and opened again** (do this at the end of the day; sometimes it needs two restarts).

**Why `migrate --seed`:** it adds new tables and new permissions safely; it never removes your data or changes your own settings.

**With branch servers:** update **head office first**, then each branch server.

## 12.5 Moving to a new server

1. Install the new server (Chapter 4 or 5) without creating keys, tables or the company, as in 12.3 step 1.
2. Take a fresh backup on the old server **after closing trading** and stop the old server.
3. Restore it on the new server (12.3).
4. Point the devices to the new address: if the address changed, open the new till address on each device and enrol it again (System → Devices → New code), after its queue has been sent.
5. Re-activate the licence within 14 days (Chapter 6.6).
