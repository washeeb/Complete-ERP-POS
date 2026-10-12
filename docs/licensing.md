# Licensing and anti-piracy

Every installation needs a **licence key from SyncLytics**. Keys are signed with SyncLytics' private key (Ed25519) and checked by the software **offline**. Head office also **checks in** with the SES Labs licence server every day.

## What the customer sees

| State | When | What works |
|---|---|---|
| Not activated | new installation | only the Licence page (shows the installation code) |
| Active | — | everything |
| Attention | 30 days before expiry; licence server not reached for 30 days; software moved to another server | everything, with a yellow banner |
| Grace | up to 14 days after expiry | everything, with a banner |
| **Read-only** | after the grace period; licence revoked; licence server not reached for 30 + 14 days; moved and not re-activated within 14 days; modified software | **view on screen only**: no changes, no sales or shifts on the tills, no printing, no downloads. Nothing is deleted; tills keep their unsent sales until the licence is renewed. |

The licence also limits the number of **branches**, **tills and devices**, **active users**, which **industry modules** can be switched on, and whether **Finance** and **HR & payroll** are included. Parts that are not licensed disappear from the menus.

## Activating (customer)

1. Sign in as the owner or general manager: you are taken to **Settings → Licence**.
2. Send SES Labs the **installation code** shown there (it starts with `SYNREQ-`).
3. Paste the key you receive (it starts with `SYN1-`) with **Enter licence key**.

To renew, SES Labs issues a new key. It reaches head office by itself at the next daily check-in, or you can paste it the same way. Branch servers receive the licence from head office.

## Issuing (SES Labs only)

One-time set-up:

```bash
php artisan license:keygen --kid=syn-2026-1 --out=/secure/offline/ses-labs-license.key
```

1. Add the printed public-key line to `config/license.php` → `public_keys`, then commit it.
2. Keep the private key file offline and backed up. Anyone holding it can issue licences.

**Done on 2026-10-12:**
- Key id `syn-2026-1`; its public half is in `config/license.php`.
- The private half is on the SyncLytics development PC in the git-ignored `.local-secrets/license-signing-syn-2026-1.json`.
- **A copy must be kept offline**, for example on a USB stick in a safe. Losing it means no new or renewed licences until a new key is added and shipped in an update.
- `license:issue --checkin=0` issues a key that never needs the licence server. It is now honoured; before, it fell back to 30 days.

Issue a key for an installation code:

```bash
php artisan license:issue --key=/secure/…/ses-labs-license.key --customer="Kofi Stores" --code=SYNREQ-… --until=2027-10-31 --branches=3 --devices=6 --users=15 --features=finance,hr --modules=grocery --server=https://licence.seslabs.com
```

- **Renew:** issue a new key with `--replaces=<old licence id>`. The installation picks it up at its next check-in.
- **Withdraw:** `php artisan license:revoke <licence id> "reason"`. The installation becomes read-only at its next check-in.
- **Licence server:** one installation run with `LICENSE_SERVER=true`, `LICENSE_PRIVATE_KEY_PATH` and `LICENSE_KID` answers `POST /api/license/checkin`. Every check-in is kept in `vendor_checkins`: the same licence on two machines, a modified copy, or usage over the licence shows up there.

## Protection layers

1. **Signed keys bound to the installation**: a random installation id, plus a fingerprint of the machine (OS machine id and database cluster id). A copied server or database asks to be re-activated.
2. **Daily check-in**: answers are signed by SyncLytics, so a fake licence server cannot keep a revoked copy alive.
3. **Release builds** (`php artisan release:build --customer=… --key=… [--with-pos]`): one copy per customer, built from committed code only (no `.env`, no secrets).
   - A **hidden build stamp** goes into every PHP file under `app/` and into the till app, so a leaked copy names the customer it was given to.
   - A **signed manifest** of the files that enforce licensing is included. If any of them is changed, the installation becomes read-only and reports it at check-in.
   - A release build trusts only the production key, not the development key.
4. **Hidden watermarks on output**:
   - Every printed document carries the licence and build reference twice: as a tiny "Ref" line, and as invisible characters inside the company name, which survive copy-paste.
   - Till receipts carry the "Ref" line, and back-office pages carry it in a meta tag.
   - `php artisan license:trace <file>` reads the hidden reference back out of copied text.

**Limits, honestly:** software installed on a customer's own server can always be studied by a determined attacker with the source code. These layers make copying hard, make modified copies stop working, and make leaks traceable. Encoding the PHP files (e.g. ionCube) would add another layer. It needs a paid licence and a loader on each server, and can be added to `release:build` later.

## Development and tests

- **Development builds** (no release manifest) also trust a development key, set by `LICENSE_DEV_PUBLIC_KEY` in `server/.env`.
- The development private key lives in the git-ignored `.local-secrets/license-dev.json`.
- Activate the dev installation with `php artisan license:issue --customer=Dev --until=2027-12-31 --activate`.
- Tests generate their own key and licence (`activateTestLicense()`).
