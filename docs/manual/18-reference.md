# 18. Reference

## 18.1 Security levels

A person's **security level** is the highest level of role they can ever be given.

| Level | Typical people |
|---|---|
| L6 · Owner | Owner / director (everything) |
| L5 · Executive | General Manager, Finance Manager |
| L4 · Manager | Branch Manager, Accountant, HR Manager, Warehouse Manager, Pharmacist, IT Admin |
| L3 · Supervisor | Supervisor, Payroll Officer, Marketing |
| L2 · Staff | Cashier, Storekeeper, Waiter, Tailor, Repair Technician, Stylist / Therapist, Sales Rep |
| L1 · Restricted | Auditor (read-only), Kitchen Staff |

**IT Admin** manages users, devices, modules and settings, but gets **no** access to sales, money or stock unless the owner also gives them a business role.

## 18.2 Roles provided

Owner · General Manager · Finance Manager · Accountant · Auditor · HR Manager · Payroll Officer · Purchasing Officer · Purchase Approver · Warehouse Manager · Storekeeper · Picker / Packer · Branch Manager · Supervisor · Teller (sells at a till; called Cashier before version 0.30) · Cashier (issues floats, receives the tellers' cash) · Income Controller (accounts: receives the day, decides differences, banks the cash) · Sales Rep · Van Sales Rep · Customer Service · Marketing · E-commerce Manager · Delivery Rider · Production Manager · Machine Operator · Quality Inspector · Pharmacist · Pharmacy Technician · Waiter / Bartender · Kitchen Staff · Repair Technician · Tailor · Stylist / Therapist · IT Admin.

You can change what a role allows under **People & access → Roles** (you can only add permissions you hold yourself).

## 18.3 Settings in `.env`

| Key | Meaning |
|---|---|
| `APP_ENV` | `production` on a live system |
| `APP_DEBUG` | `false` on a live system (never `true`: it shows technical details) |
| `APP_URL` | The address people use to reach this server |
| `APP_KEY` | Encryption key (created once by `key:generate`). **Back it up, never change it.** |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Database connection (`127.0.0.1`, `5432`, `erp`, `erp`, your password) |
| `ERP_NODE_ROLE` | `hq` (head office or single server) or `branch` (branch server) |
| `ERP_NODE_ID` | Short unique id of this server (`HQ`, `B01`…), stamped on every record |
| `ERP_AUDIT_KEY` | Audit-log seal (created once by `erp:audit-key`). **Back it up, never change it.** |
| `REVERB_*` | Live updates (optional, 4.9 / 5.9) |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Outgoing e-mail (Chapter 16.1). `MAIL_MAILER=log` only writes e-mails to the log |
| `BACKUP_PASSPHRASE`, `BACKUP_PG_BIN`, `BACKUP_TIME`, `BACKUP_KEEP_LOCAL` | Backups (Chapter 12.2). **Keep a written copy of the passphrase away from the server.** |
| `GRA_EVAT_URL`, `GRA_EVAT_KEY`, `GRA_EVAT_TIN`, `GRA_EVAT_TIMEOUT` | GRA E-VAT access from GRA (Chapter 11.5) |
| `BRAND_NAME`, `BRAND_VENDOR`, `BRAND_WEBSITE` | The product's name, the company that publishes and licenses it (SyncLytics unless a reseller supplies it), and its website (normally left as delivered) |
| `ERP_SUPPORT_EMAIL` | Where problem reports and the evening pilot report go when the go-live plan names no address (Chapter 17.6); empty = SyncLytics support (info@synclyticsenterprisesystems.com) |
| `BRAND_SUPPORT_PHONE`, `BRAND_SUPPORT_EMAIL` | The publisher's hotline and e-mail on the Licence page and in licence messages; empty = SyncLytics' own (normally left as delivered) |
| `SESSION_SECURE_COOKIE` | `true` once the site uses HTTPS (the Ubuntu script sets it) |
| `ANTHROPIC_API_KEY` | Only if you choose Claude (cloud AI) in Chapter 15. Leave empty for no cloud AI. |
| `BROADCAST_CONNECTION`, `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER` | Leave as provided |

After changing `.env`, run `php artisan optimize`.

## 18.4 Network ports

| Port | Used by | Open to |
|---|---|---|
| 443 | The web server, Caddy (back office, tills, live updates) | Shop network, or the internet with an internet name |
| 80 | Sends visitors on to https; on a server without an internet name, also hands out its certificate (`/ca.crt`) | As 443 |
| 9001–9004 | PHP workers behind the web server (Windows) | **This server only** |
| 8080 | Live updates (Reverb) | **This server only** (reached through 443) |
| 5432 | PostgreSQL | **This server only** — never open it to the network |
| 22 | SSH (Ubuntu remote administration) | Your administrators only |

## 18.5 Useful commands (in the `server` folder)

| Command | What it does |
|---|---|
| `php artisan erp:install` | Create the company and owner (once) |
| `php artisan erp:demo-catalog --as=<email>` | Sample products and stock — **test installations only** |
| `php artisan migrate --seed --force` | Create or update the database structure (installation and updates) |
| `php artisan optimize` / `optimize:clear` | Apply `.env` changes / clear cached settings |
| `php artisan schedule:work` | The scheduler (normally runs as a task or cron, not by hand) |
| `php artisan erp:sync` | Sync a branch server with head office now |
| `php artisan erp:node:join <hq-url> <hq-id> <company-id>` | Pair a branch server with head office |
| `php artisan erp:stock-check` after a large import | Confirms stock levels still match the ledger |
| `php artisan erp:bill-memberships` | Raise membership invoices that are due (runs daily by itself) |
| `php artisan erp:backup` / `erp:backup --verify` | Encrypted backup now / check the newest one (both also run by themselves) |
| `php artisan erp:backup:key` | Create the backup passphrase (once) |
| `php artisan erp:backup:restore <file>` | Put a backup back (replaces the data) — Chapter 12.3 |
| `php artisan erp:health` | All health checks (System → Health); fails when something needs fixing |
| `php artisan erp:evat-batch --now` | Send the GRA E-VAT till sales waiting (daily mode) |
| `php artisan erp:load-test` | Speed test of sales and reports on a test system (leaves no data) |
| `php artisan erp:pentest-setup --license=<file>` | Penetration test machine only: fill a **new, empty** installation with made-up companies and test sign-ins (Chapter 17.9) |
| `php artisan erp:reports` | Send scheduled reports that are due (runs every 15 minutes by itself) |
| `php artisan erp:pilot-report --show` / `--now` | Print / e-mail the pilot report now (sent every evening by itself during a pilot — Chapter 17.6) |
| `php artisan erp:insights` | Refresh forecasts and alerts now (runs daily at 04:30 by itself) |
| `php artisan license:status` | Licence state and limits |
| `php artisan erp:audit-verify`, `erp:stock-check`, `erp:gl-check` | Health checks (Chapter 13) |

## 18.6 Till keyboard shortcuts

| Key | Action |
|---|---|
| F2 | Search / scan |
| F3 | Today's route (vans) |
| F4 | Choose customer |
| F6 | Tables (Restaurant) |
| F7 | Repairs (Electronics) |
| F8 | Return |
| F9 | Alterations (Fashion) |
| F10 | Appointments (Services) |
| F12 | Pay |

## 18.7 Go-live checklist

The checklist lives in the software: **System → Go-live** (Chapter 17) shows each step in order and ticks most of them by itself. Before the go-live day also confirm:

- [ ] Licence **Active** with the right modules and limits; two-step sign-in for every manager, recovery codes stored safely.
- [ ] Branch, warehouse and till codes correct (they cannot change later); tax groups checked by your accountant.
- [ ] Every user with the right role and scope; PINs set; IT Admin without business roles.
- [ ] Every till enrolled, printing, opening the drawer, and tested **offline**.
- [ ] Nightly backup running, copied off the server, and a **restore practised** (Chapter 12.3); **System → Health** all green.
- [ ] `.env`, the backup passphrase, the owner password and recovery codes stored safely, away from the server.
- [ ] Everyone knows the rollback plan (Chapter 17.8).

## 18.8 About this guide

This guide is generated from the files in `docs/manual/` and updated with every release. The software version and date are on the cover page.
