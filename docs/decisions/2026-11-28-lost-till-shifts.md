## A shift left open by a lost till
- **Both ways (owner 2026-11-28):** continue it on the replacement till with a manager's PIN, or close it in the back office.
- **Continue = move the shift to the new device**, not a new shift: the drawer's cash belongs to that shift; one count for one drawer. The old device id is kept so its queued sales still land in it.
- **Only from a till that is gone:** revoked, or silent for 15 minutes (no request and no health report). A till still in use never loses its shift to another device.
- **Manager approval by PIN with `cash.setup`,** the permission that already closes shifts in the back office; no new permission. The manager may approve their own shift (as for till settings): one-person shops.
- **Vault shifts close in the back office with a blind count** that stands for the teller's; the cashier's receive (blind, with recount) and the variance flow stay unchanged, so the cash-control chain is not bypassed.
- **Revoking does not close the shift by itself:** the cash must be counted; revoking points to the two ways out.
