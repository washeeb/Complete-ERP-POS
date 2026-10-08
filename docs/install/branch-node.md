# Branch server (branch node)

A branch server runs the same application as head office, on a PC or small server at the branch.
Tills at the branch talk to it over the shop LAN, so the branch keeps trading when the internet to HQ
is down. It exchanges changes with HQ automatically (see `docs/sync.md`).

> The step-by-step version for non-specialists is Chapter 10 of the **Installation & Setup Guide**
> (`docs/manual/`).
>
> **Status:** the sync engine and pairing are complete and tested. The one-click Windows installer
> (FrankenPHP single binary + PostgreSQL + scheduler service) is built in Phase 13. Until then, set a
> branch server up like the development install below.

## Prerequisites
Windows 10/11 Pro or Ubuntu 24.04 · 8 GB RAM · SSD · PostgreSQL 17 · PHP 8.4 with pdo_pgsql, intl,
bcmath, sodium · fixed LAN IP for tills · UPS recommended · correct clock (NTP).

## Steps
1. Install the code and dependencies (as in `README.md`), create the database and `.env`:
   ```
   ERP_NODE_ROLE=branch
   ERP_NODE_ID=B01            # unique per server; must match what HQ registers
   ERP_AUDIT_KEY=             # php artisan erp:audit-key
   APP_URL=http://<branch-server-LAN-ip>
   ```
2. `php artisan key:generate && php artisan erp:audit-key && php artisan migrate --seed`
   (on a branch server the seeder only installs permissions; everything else comes from HQ).
3. At HQ: *System → Sync Center → Add branch server* with id `B01`. Copy the command and the secret.
4. On the branch server: `php artisan erp:node:join https://<hq-address> <HQ node id> <company id>`,
   paste the secret. It downloads company data, people, catalog, prices and stock.
5. Keep the scheduler running every minute (`php artisan schedule:work` as a service, or Task Scheduler /
   cron calling `php artisan schedule:run`). It runs `erp:sync`.
6. Register the branch tills **on the branch server’s back office** (System → Devices) and enrol them there.

## Verify
- Branch back office → *System → Sync Center*: head office shows **OK** and a recent *Last sync*.
- Make a test sale on a branch till; at HQ it appears under *Sales → Till sales* within a minute.
- `php artisan erp:stock-check` and `php artisan erp:audit-verify` pass on both servers.

## Upgrade
Upgrade HQ first, then branch servers (newer HQ events wait as *failed — update this node* until the
branch is upgraded, then apply automatically). Run `php artisan migrate --seed` after each upgrade.

## Backup / restore
Back up the branch database nightly (Phase 13 automates encrypted backups). A lost branch server can be
rebuilt: fresh install → join → full sync; sales already sent to HQ are safe there.

## Uninstall
At HQ: *Sync Center → (row menu) → Disable this peer* — its requests are refused from then on; nothing is
deleted. On the branch server stop the scheduler and keep a final backup.
