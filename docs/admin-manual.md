# Administrator manual

## First run

1. Install the server for your deployment mode: see the **Installation & Setup Guide**
   (`docs/manual/Ervanta-Installation-Guide.pdf`; for development see `README.md`).
2. Create the audit key, then the company and its owner:
   ```
   php artisan erp:audit-key
   php artisan erp:install
   ```
   Back up `ERP_AUDIT_KEY` from `.env` with your other secrets.
3. Open `/admin`, sign in as the owner.
4. **Two-factor:** you are asked to set up an authenticator app. Save the recovery codes somewhere safe
   (not on the same phone). You can add a **passkey** later from your profile.
5. **Setup wizard:** deployment mode → company (country, currency, time zone, optional legal entity
   with TIN/VAT) → branches (code, name, number of tills, head office, modules). The wizard is
   pre-filled with the demo layout; change anything. Codes appear in receipt numbers and can't be
   changed later.

Tax profile, chart of accounts, hardware and sync settings join the wizard in the phases that build them.

## Everyday tasks

| Task | Where |
|---|---|
| Add a branch / warehouse / till | Company → Branches → New branch, or open a branch → Warehouses / Tills |
| Add a person | People & access → Users → New user (then grant access on the next screen) |
| Give someone access | Users → open the person → *Grant access*: role, where it applies, optional limits, optional expiry + reason |
| Cover for someone temporarily | *Grant access* with "Temporary until" and a reason; it ends by itself |
| Take access away | Users → person → access row → *Revoke* (reason required) |
| Someone left | Users → person → untick *Active* (signs them out everywhere, including tills) |
| Set or reset a till PIN | Users → person → *Set POS PIN* (also clears a PIN lockout) |
| Change what a role can do | People & access → Roles (you can only add permissions you hold) |
| Set up a new till PC/tablet | System → Devices → New device → note the code shown once → enter it on the device |
| Lost or stolen device | System → Devices → *Revoke* |
| Turn modules on per branch | System → Modules |
| Security settings | System → Settings |
| Check the audit log is intact | System → Audit log → *Verify chain* |
| Download a list or report | **Export** (top right) → CSV (Excel) or PDF — uses the current search and filters |
| Import products, prices, customers, suppliers, opening stock | System → Import data (CSV): template, *Check file*, then *Import* |
| Correct stock (damage, theft, found, mistakes) | Stock → Stock adjustments (approval above the limit in Settings → Stock) |
| Stock take | Stock → Stock counts (count sheet PDF/CSV, import counts) or Stock on hand → Stock sheet |

## Backups, restore, upgrades

Written in Phase 13 with the installers (encrypted backups with a tested restore). Until then this
system holds demo data only.
