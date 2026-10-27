# 13. Troubleshooting

Find the symptom, then try the fixes in order.

## Installation

| Symptom | Cause and fix |
|---|---|
| `php` is not recognised | PHP is not on the path. Repeat 4.3 step 6, then **close and reopen** PowerShell. |
| `could not find driver` or `pdo_pgsql` missing | The extension is not switched on. Check `php.ini` (4.3 step 5) and that `extension_dir = "ext"`. Run `php -m` to confirm. |
| PHP does not start: *VCRUNTIME140.dll missing* | Install the Visual C++ Redistributable (4.3 step 1). |
| `SQLSTATE[08006] … password authentication failed for user "erp"` | `DB_PASSWORD` in `.env` differs from the one in 4.2/5.3. Fix `.env`, then `php artisan optimize`. |
| `SQLSTATE[08006] … Connection refused` | PostgreSQL is not running. Windows: Services → *postgresql-x64-17* → Start. Ubuntu: `sudo systemctl start postgresql`. |
| `composer install` fails with a PHP extension message | Switch on the extension it names in `php.ini` (Windows) or install `php8.4-<name>` (Ubuntu). |
| `ERP_AUDIT_KEY is missing` | Run `php artisan erp:audit-key` (only on a new installation — never replace an existing key). |
| The page shows *500 Server Error* | Look at the end of `server/storage/logs/laravel.log` for the reason. After changing `.env`, always run `php artisan optimize`. |
| Ubuntu: *Permission denied* in the log | `sudo chown -R www-data:www-data storage bootstrap/cache` |
| Uploading a CSV file does nothing, or *failed to upload* | PHP cannot write its temporary upload file. Windows: check `upload_tmp_dir` in `php.ini` points to an existing folder (4.3 step 5) and restart the server task. |
| `/pos/` says the till app is not installed | Build it: in `pos`, `npm install` then `npm run build:server` (4.7). |

## Back office

| Symptom | Cause and fix |
|---|---|
| Lost the authenticator phone, or deleted the app | Use a **recovery code**: type it where the 6-digit code is asked for. If those are lost too, someone who manages users (an owner or the IT Admin) opens **Users**, picks the person and presses **Reset two-step sign-in**, after checking by phone or in person that it is really them. **Why:** whoever can reset it can take over the account. Nobody can reset themselves, nor anyone above their own level. |
| The owner is locked out (no app, no recovery code) | Nobody in the company is above the owner, so SyncLytics support does it on the server after checking it is really the owner: `sudo -u www-data php artisan erp:mfa-reset --email=owner@example.com` (in the `server` folder). The owner then signs in with username and password and scans a new code. **Why on the server:** only the people who run the server can type it, and it is kept in the audit log. Afterwards, save the new recovery codes away from the phone. |
| A menu is missing | The module is off for your branches (Chapter 8), your licence does not include it (Chapter 6), or your role does not allow it (ask the owner). Type part of the page's name in **Search the menu** (top of the side menu, or press `/`) to check. |
| *You can't open this page* | The page says **why** (for example *it needs the permission "View users and their access"*) and **who can give access**. Press **Ask for access**: they get a notification with the page and your message, and decide. A link you cannot use should not show at all; if you find one, tell SyncLytics support. |
| *This is managed at head office* | You are on a branch server; change master data at head office (Chapter 10). |
| Everything is read-only, with a red banner | Licence expired, withdrawn or not checked in for 44 days (Chapter 6.4). Renew; nothing is lost. |
| Passkey button does nothing | Passkeys need HTTPS or `localhost`. Use the authenticator app. |
| Pages are slow | Open **System → Health**. *PHP code cache* must say *On* (if not, update with the simple way, Chapter 12.4). *Slow pages* lists what was slow, how often and how many database reads it made: send it to SyncLytics support. Run `php artisan erp:load-test --force` outside trading hours for a full speed check (it leaves nothing behind). For a page-by-page check, `php artisan erp:page-timings` (in the `server` folder) opens every page as the owner and lists the slowest with their database reads; it changes nothing. Send its output to SyncLytics support. Its times are a little slower than in the browser (the code cache is usually off for commands), so a page with few, quick reads that shows about one second is normal there; *Slow pages* under System → Health shows what people actually waited. |

## Tills

| Symptom | Cause and fix |
|---|---|
| Till shows *working offline* but the server is up | Network or Wi-Fi between till and server. Open `https://<server-address>/up` on the till's browser. On Windows servers, check the firewall rule (4.8, step 8). |
| The browser warns *Your connection is not private* | The device does not trust the server's certificate yet (4.9), or the server's address changed since installation (run the installer again, then 4.9 on each device). |
| The till does not work at all when the server is off | Offline was never enabled on that device: setups B/C/E need 9.3; then open the till once while the server is on. |
| Enrolling a till fails on a network address (`http://192.168…`) | The browser does not trust the address yet: do 9.3 on that device. |
| Cashier cannot sign in offline | They must have signed in on **this** till while it was online within the offline period (System → Settings → Offline till sign-in lasts). |
| *Your sign-in on this till has ended* (payment screen) | The cashier's sign-in lasts 12 hours from the last time the till was used, so a till left idle (e.g. overnight) asks again. Enter your own username and PIN in the box; the sale completes and nothing in the cart is lost. **Why:** a till nobody is using should not stay signed in for anyone who walks up to it. Older tills showed *No offline sign-in for you on this till* instead; update the till (close and reopen it). |
| *PIN locked* | Too many wrong PINs. A manager resets it: People & access → Users → the person → **Set POS PIN**. |
| Red banner: items refused | A supervisor opens **Sales → Review queue** (*sync failed*) to see why, fixes it, then presses **Retry refused** on the till. |
| *The price … changed. Refresh the till* | The price changed at head office while the item was in the cart. Remove the line and add it again. |
| *Open a shift on this till before selling* | Open the shift (count the float). |
| *The till clock is ahead of the server* | Set the till's clock to automatic (Windows Settings → Time). |
| Receipt is tiny or cut | Printer paper size 80 mm, margins *None*, scale 100 %, headers off (9.7). |
| Scanner types the code but nothing happens | The scanner does not send Enter (9.8). |
| Desktop till: *Could not reach …* on the set-up page, or a blank window | The server address is wrong. Use the server's name exactly as in the browser (`https://erp.clientdomain.com`), not its IP address. Correct it and click **Save** (9.7). Old app versions open the saved address with no way back: clear the settings as in 9.7, *Starting the desktop till again from scratch*. |
| Till did not pick up a new version | Close the till app completely and open it again (twice if needed). |

## Branch servers and online stores

| Symptom | Cause and fix |
|---|---|
| Sync Center: *Sync request time … off* | Server clocks differ by more than 5 minutes; set automatic time on both. |
| Changes *failed — update this node* | Update the branch server to the same version as head office (12.4). |
| A branch seems out of step | Sync Center → **Full resync from this peer**, then `php artisan erp:stock-check`. |
| Online store not updating | Is the scheduler running (4.8 / 5.9)? Is the channel **Active**? Look at the channel's failed updates and **Retry failed updates**. |
| No SMS arrive | Check the SMS gateway address in Settings and that the phone number is on the booking. Test your provider separately. |

## Products imported twice

If a product file was imported more than once with **Products — create new** before version 0.35 (when one import did both), each product may exist two or three times. On the server, in the `server` folder:

```bash
php artisan erp:merge-duplicate-products
```

This only **shows** what it would do: for each product, the copy created first is kept, and the later copies (same name, industry and unit, from a later import) are listed. Then:

```bash
php artisan erp:merge-duplicate-products --apply
```

Barcodes on the later copies move to the kept product; a later copy that was never used is removed; one that has stock, sales or orders is switched off instead (it keeps its history). Every step is in the audit log. Two different products with the same name in one file stay two products.

**Why on the server:** removing products is permanent, so it is a deliberate step by the person looking after the server, after the automatic backup the update takes.

## Health checks

**System → Health** shows the state of the server at a glance: database, scheduler, live updates, last backup and its check, disk space, live-site settings, HTTPS, e-mail, licence, PHP's code cache, slow pages, and anything stuck (messages, webhooks, GRA E-VAT, sync). Each line says what to do. Below the checks, **Slow pages** lists every page and till call that took over a second in the last 7 days, worst first.

![System → Health](img/health.png)

The same checks run with `php artisan erp:health` (useful for monitoring tools: it fails when something needs fixing).

### Individual checks (run in the `server` folder)

| Command | What it checks |
|---|---|
| `php artisan about` | PHP, database and settings in use |
| `php artisan erp:audit-verify` | The audit log is complete and untouched |
| `php artisan erp:stock-check` | Stock levels match the stock ledger |
| `php artisan erp:gl-check` | The accounts agree with sales, stock, customers and suppliers (Finance) |
| `php artisan license:status` | Licence state, limits and last check-in |
| `php artisan erp:load-test --force` | Speed: posts test sales and times the heavy reports, searches and the till catalogue on your real data, then undoes everything. Lines over a second are marked *SLOW* |

## Website copiers and robots

The system is a private business system. Tools that try to download a whole website (HTTrack, Cyotek WebCopy, Offline Explorer, wget and similar) are refused with *Copying this site is not allowed* — by the web server and again by the application — and anyone not signed in who asks for more than 120 pages a minute is told to wait a minute. Search engines and web archives are asked not to index or keep any page.

**What a copier could get anyway:** only the sign-in page and the till's screen code, which is compressed and does nothing without your server, an enrolled till and a valid licence. The system's code, the database, passwords and keys never leave the server. **Why:** copying the pages cannot copy the system, and the blocking keeps such tools from loading the server.

If a legitimate tool of yours is refused (for example a monitoring service), give it a normal browser name, or ask SyncLytics support.

## Getting help from SyncLytics

| | |
|---|---|
| **Hotline (support)** | 024 563 6889 |
| **Office** | 055 668 6975 · 050 693 8589 |
| **E-mail** | info@synclyticsenterprisesystems.com |
| **Website** | synclyticsenterprisesystems.com |
| **Address** | Puff Cl, Race Course St, Tabora, Accra (GhanaPost GPS GC-027-0884) |

The quickest way is **Report a problem** in the back office (top right) or on the till: it attaches the technical details for you, and a picture of the screen (the till takes it by itself; in the back office press **Win+Shift+S**, then **Ctrl+V** in the box). Whoever may give support access can tick *Let SyncLytics support connect* there.

**Support access (free).** **System → Support access → Give support access** lets SyncLytics support reach your tills and computers to help — *see only* or *see and control*, every branch or one, for a few hours or until a problem report is closed. Everyone signed in sees a banner while it is open, and you can withdraw it at any time. **Why:** support can fix things without travelling, and only when you say so. The company's own staff connecting to devices (training, IT) is the paid *Remote access* add-on.

**Remote connections and trading hours.** **System → Settings → Remote access**: by default a connection is unattended outside trading hours (07:00–21:00 unless changed) and must be accepted by the person at the device during them; a branch can choose differently (Company → Branches).

Otherwise send: what you were doing, the exact message (a screenshot helps), the time it happened, and the last lines of `server/storage/logs/laravel.log`. **Never** send your `.env` file, passwords, PINs or recovery codes.
