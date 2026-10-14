# 19. Case study: a cloud server from start to finish

This chapter follows one real installation from start to finish: the first client's head office server, rented from **Contabo** and reached on the client's own internet name. It shows the order of the steps, what each screen asked for, and the problems met on the way. Use it beside Chapter 5, which explains each step in detail.

The client's real name, internet name and server address are replaced here by examples: `erp.clientdomain.com` and `203.0.113.10`.

## 19.1 What was decided first

| Decision | Choice | Why |
|---|---|---|
| Where the server runs | Rented cloud server (setup D) | Two shops in different places; no server room; the tills keep selling if the internet drops. |
| One server or shared | **One server for this client** | The standard: own data, licence, backups and updates. A shared server (Chapter 5.0) is only for small clients starting out. |
| Provider and plan | Contabo **Cloud VPS 6**: 6 cores, 12 GB memory | 12 GB lets the database keep the business in memory. The smaller plan saves very little and leaves no room to grow. |
| Disk | **100 GB NVMe** (not the 200 GB SSD at the same price) | The database works much faster on NVMe; 100 GB lasts two shops for years. |
| Region | **European Union** (Germany), free | Contabo serves Africa from Germany; its own test showed it as fast as the paid UK option from Ghana. |
| System | **Ubuntu 24.04** | What the install script is built and tested for. |
| Daily server copy | Contabo **Auto Backup** | A copy of the whole server kept off the server, on top of the system's own encrypted backups. |
| Term | 24 months, paid up front | Cheapest monthly rate; matches the client's 24-month price lock. |
| Internet name | `erp.` in front of the client's own domain | Leaves the main name free for a website later. |

## 19.2 Renting the server (Contabo)

1. On contabo.com: **VPS → Cloud VPS 6 → Get Started**.
2. In the order page, check every section, because some defaults are not what you want:
   - **Term:** 12 or 24 months (cheaper than monthly).
   - **Region:** European Union.
   - **Storage:** change the default 200 GB SSD to **100 GB NVMe**.
   - **Image:** Ubuntu; open its version list and choose **24.04**.
   - **Auto Backup:** the default is *No Data Protection*: choose **Auto Backup**.
   - **Password:** choose a long one (20+ characters) and keep it in a password manager. Never send it by WhatsApp or e-mail.
3. Pay. Contabo e-mails the server's **IP address** when it is ready (minutes to a few hours).

**Lesson:** the order page's defaults (SSD, no backup) are the cheaper choices, not the right ones for a business server. Go through it line by line.

## 19.3 Pointing the internet name at the server (Namecheap)

1. Namecheap → **Domain List** → **Manage** next to the domain.
2. **Domain** tab: *Nameservers* must say **Namecheap BasicDNS**.
3. **Advanced DNS** tab → **Add New Record**: type **A Record**, host `erp`, value = the server's IP address, TTL Automatic → green tick.
4. Check it from any PC (it took a few minutes here):
   ```
   nslookup erp.clientdomain.com 8.8.8.8
   ```
   It must answer with the server's IP address.

**Why before installing:** the install script asks Let's Encrypt for the HTTPS certificate, and Let's Encrypt checks that the name leads to this server.

**Lesson:** a newly bought domain does not answer at all until a record is added. That is normal.

## 19.4 Getting the installation files

The installation uses a **release package made for this client** (`release:build`, run by SyncLytics): the code stamped with the client's name and signed with the SyncLytics key, plus the till app. The Ubuntu `install.sh` is copied beside it.

**Lesson (fixed on 14 October 2026):** the first package built for this client showed that the signed manifest was never copied to servers, so tamper protection was silently off everywhere. Since the fix, the package is checked before sending (signature valid, protected files unchanged).

## 19.5 Copying the files to the server (from Windows)

Windows 10 and 11 already have the tools. In PowerShell:

```
scp "<folder>\ses-labs-<client>-<build>.zip" "<folder>\installer\ubuntu\install.sh" root@203.0.113.10:/root/
```

- **First time:** *"The authenticity of host … can't be established"* — type `yes`. Your PC remembers the server and will warn if it ever changes. (To be certain, compare the fingerprint in Contabo's **VNC console** with `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`.)
- **The password does not show** as you type. To paste it, **right-click once** (Ctrl+V may paste nothing).
- **"Connection closed"** right after the password question happened once here. The second try worked. Causes: the password typed too slowly or wrongly, or the new server still finishing its first start.

Done when both files show **100%**.

## 19.6 Installing

1. Connect: `ssh root@203.0.113.10` (same password). The prompt becomes `root@vmi…:~#`.
2. **Do not** accept the *"New release … available, run do-release-upgrade"* offer: stay on Ubuntu 24.04, which the install script is tested on.
3. Set the server's clock to Ghana time (Contabo's servers start on German time), so the nightly backup and the logs use Accra times:
   ```
   timedatectl set-timezone Africa/Accra
   ```
4. Run the install script with the internet name:
   ```
   sudo bash /root/install.sh --zip /root/ses-labs-<client>-<build>.zip --domain erp.clientdomain.com --email info@synclyticsenterprisesystems.com
   ```
   It shows steps **1/10 to 10/10** and ends with *Done: the server answers at https://erp.clientdomain.com/admin*. Here the packages, libraries and database took about 5 minutes on a new Cloud VPS 6.
5. **Write down the backup passphrase** it shows, and keep it with the SyncLytics key backup. Backups cannot be restored without it.
6. Answer its questions to create the **company and the owner account** (Chapter 7).

**What happened here:** the script ended at step **9/10** without a message, so the firewall, the final check, the passphrase display and the company questions did not run. The cause was the SSH port lookup, which fails on Ubuntu 24.04 when SSH is started on demand. Fixed on 14 October 2026: the script now carries on, and any unexpected stop names the line. Everything before step 9 had worked, so the remaining steps were done by hand:

```
ufw allow 22/tcp && ufw allow 80/tcp && ufw allow 443/tcp && ufw --force enable && ufw status
curl -fsS https://erp.clientdomain.com/up > /dev/null && echo "Site OK"
grep '^BACKUP_PASSPHRASE=' /var/www/seslabs/server/.env
cd /var/www/seslabs/server && php artisan erp:install && chown -R www-data:www-data storage bootstrap/cache
```

The site answered over HTTPS straight away, with a Let's Encrypt certificate valid for three months and renewed automatically.

## 19.7 Licence and first sign-in

1. Open `https://erp.clientdomain.com/admin` and sign in as the owner. The padlock shows a valid certificate.
2. **Licence** page: copy the **installation code** (`SYNREQ-…`) and send it to SyncLytics. The code names the release build, which confirms the signed release is installed.
3. SyncLytics issues the key for exactly what the client bought. Here that was:
   - 2 shops, 4 tills and devices, 10 users;
   - Pharmacy, Grocery (to start the retail shop) and Retail & Wholesale (for when it is released);
   - accounts, and HR and payroll (added free for this client);
   - valid to the end of the set-up period. The full-year key follows when the first year's subscription is paid at go-live.

   Paste it on the Licence page → **Activate**. A key re-issued with more features simply replaces the earlier one.
4. **Set up your company** wizard, *Branches & modules* page. It opens with a **demo layout**: head office plus Grocery, Restaurant and Pharmacy branches, 2 tills each. Change it to the client's real shops **before** clicking *Finish setup*, or the licence limits refuse it. Here:
   - delete the demo branches the client does not have;
   - the pharmacy, code `PH`, **head office on**, module **Pharmacy** only, 2 tills;
   - the retail shop, code `RT`, module **Grocery / Supermarket** only, 2 tills;
   - leave *Retail & Wholesale* unticked (not released yet).
5. **Books:** a client that is **one registered company** running both shops switches off *System → Settings → Finance → Separate books for each industry* straight away, before anything is posted.
6. Company details and TIN under *Company → Legal entities*; users and roles (Chapter 7).

**Lesson:** read the wizard's last page line by line. Its demo branches look finished but are only an example.

## 19.8 After installing (the same day)

| Check | Where | Expected |
|---|---|---|
| Server health | System → Health | Scheduler OK, backups OK, *PHP code cache: On*, HTTPS OK |
| A backup outside Contabo | System → Backups → add a second destination (Chapter 12.2) | Contabo's own backup is with the same provider; a second place protects against losing both |
| Firewall | Contabo panel and the server (`ufw status`) | Only SSH (22), 80 and 443 open |
| Remote login | Your PC | Move from the password to an SSH key, and restrict port 22 to your office if possible |
| Tills | Each till device | Pair it (Chapter 9); it keeps selling if the internet drops |
| Speed | Outside trading hours: `php artisan erp:load-test --force` in `/var/www/seslabs/server` | No line marked *SLOW* |

## 19.9 Time and cost (this installation)

| Item | Amount |
|---|---|
| Contabo Cloud VPS 6, 24 months | about $7.20 a month, paid up front |
| Auto Backup | $4 a month |
| Domain | the client's own (bought separately) |
| SyncLytics time | renting and DNS 30 minutes; copying and installing 30 minutes; licence and first set-up 1 hour |
