# Launch checklist (Phase 13)

Go live only when every line is ticked. Most are checked automatically on **System → Health** / `php artisan erp:health`. The data migration, figures check, parallel run, go-live backup and rollback are run from **System → Go-live** (Phase 14, `docs/go-live.md`, installation guide chapter 17).

| # | Check | How |
|---|---|---|
| 1 | Server installed with the installer/script (or by hand), `APP_ENV=production`, `APP_DEBUG=false` | Health: *Live site settings* |
| 2 | HTTPS for every server (the Windows installer always sets it up; Ubuntu with a domain); `SESSION_SECURE_COOKIE=true`; on a shop network every device trusts the server's certificate | Health: *HTTPS*; guide 4.9 |
| 3 | Scheduler and relay running | Health: *Scheduler*, *Relay* |
| 4 | Backup passphrase set **and written down away from the server** | Health: *Backup passphrase*; owner confirms |
| 5 | At least one backup destination outside the server; last night's backup OK | Health: *Backups* |
| 6 | Restore check passed this week; a full restore practised on a spare PC | Health: *Restore check*; Chapter 12.3 |
| 7 | Licence activated (production key) | Health: *Licence* |
| 8 | Tax set-up checked by the accountant (VAT/NHIL/GETFund, PAYE, SSNIT) | Pricing & tax, Payroll rules |
| 9 | GRA E-VAT mode chosen and tested in GRA's sandbox (if registered) | Finance → GRA E-VAT |
| 10 | E-mail sending works (or deliberately off) | System → Messaging → test e-mail |
| 11 | SMS / WhatsApp provider tested, sender ID approved (if used) | System → Messaging → test |
| 12 | Owner and managers have MFA; IT Admin has no business permissions | People & access |
| 13 | Every till enrolled, test sale printed, drawer opens, offline sale queues and syncs | Chapter 9 |
| 14 | Opening stock and balances loaded and checked (`erp:stock-check`, `erp:gl-check`) | Chapter 14 |
| 15 | Audit chain verifies | System → Audit log → Verify chain |
| 16 | Disk space healthy | Health: *Disk space* |
| 17 | Staff trained on the till; supervisor knows the review queue | Training |
| 18 | Rollback plan known (guide 17.8); go-live backup taken today and copied off the server | System → Go-live |
| 19 | Figures match the old system; "Opening balances (to clear)" is zero | System → Go-live → Check the figures |
| 20 | Support e-mail set; problem reports and the pilot report arrive | System → Go-live → Plan; Show today's report |
| 21 | Independent penetration test done; no open Critical or High findings; the firm's retest letter filed (once per release line, before the first internet-facing customer) | `docs/pentest/` |
