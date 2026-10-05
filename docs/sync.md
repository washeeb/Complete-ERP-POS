# Offline working and sync

Two separate things keep trading going when connections fail:

1. **Tills work without their server.** A till keeps selling when it cannot reach the server it
   belongs to (branch server or HQ), and sends its work later.
2. **Branch servers work without HQ.** A branch server keeps the whole branch running (tills, back
   office, stock) when the internet to HQ is down, and exchanges changes with HQ when it returns.

Nothing is ever overwritten by sync: sales, payments and stock movements are records that are
*added*; master data (products, prices, people…) has one owner — head office.

---

## 1. Tills without their server

### What works offline
Signing in by PIN, opening and closing shifts, selling with cash, mobile money (manual
transaction ID) and card (standalone terminal), receipts. The till shows **“Server not answering —
working offline”** (server down) or **“No network”** (no connection at all).

### How it is kept safe
- **Offline sign-in.** When someone signs in on a till *while it is online*, the server gives that
  till a signed **offline grant** for that person: valid for *Settings → Offline till sign-in lasts*
  (default 72 hours). Offline, their PIN unlocks the grant (checked on the till with a slow hash; the
  PIN itself is never stored). A person who never signed in on that till online cannot use it offline.
- **The queue.** Offline (and whenever anything is already waiting), shift opens, sales and shift
  closes go into an ordered queue on the till, in IndexedDB. Receipts are printed straight away with
  their final number and the note *“Recorded on this till while offline”*.
- **Sending.** Every 15 seconds while something waits and the server answers, the till sends the queue
  in order, in batches, **signed with the till’s own key** (created on the device at enrolment, cannot
  be copied out). Items leave the queue only when the server confirms them. Sending twice is harmless.
- **Receipt numbers never collide**: each till device has its own series letter (HQ-T01**A**-000123);
  a reinstalled or backup device gets the next letter.

### What the server does with offline work
Money and goods have already changed hands, so an offline sale is **never refused** for these
reasons — it is recorded as it happened and a **review item** is raised instead:

| Situation | Review item |
|---|---|
| Sold at a price different from the current one (e.g. HQ changed it during the outage) | *Sold offline at a different price* (lines and both prices) |
| Sold after the person’s offline sign-in expired | *Sold after the offline sign-in expired* |
| The person was deactivated or lost till access before the till synced | *Sold by a person or device later revoked* |
| Till clock more than 10 minutes ahead | *Till clock was wrong* |
| Stock went below zero (e.g. two tills sold the last unit) | *Stock went below zero after syncing* |

It **is** refused (and kept on the till as *needs attention*, plus a *sync failed* review item) when the
offline grant is not genuine, the batch signature is wrong, the arithmetic does not add up, or the
shift it belongs to never arrived. A supervisor fixes the cause and presses **Retry refused** on the till.

Online sales stay strict: a changed price is refused so the cashier re-rings it.

### Reinstalling or replacing a till with unsent sales
On the old till: **Export unsent sales** (banner) → a signed file. In the back office:
*System → Sync Center → Import till file*. It is accepted even if the old device was revoked, because
the file is signed by that device’s own key. Then register and enrol the new device.

---

## 2. Branch servers and head office

### Who owns what (conflict policy)
| Group | Flows | Owner |
|---|---|---|
| Company, branches, tills, modules, settings | HQ → branches | HQ |
| Users, roles and access (password and PIN hashes included) | HQ → branches | HQ |
| Products, units, barcodes, categories, brands | HQ → branches | HQ |
| Prices, price lists, tax | HQ → branches | HQ |
| Stock movements | both ways | the node where the movement happened |
| Till sales, shifts, devices | branches → HQ | the branch |
| Review items | branches → HQ | the branch |

**HQ wins** is enforced at the source: a branch server refuses edits to master data (*“This is
managed at head office…”*), so master data cannot conflict. Sales, payments and stock movements are
append-only, so merging them cannot conflict; negative stock after a merge raises a review item.
Customers (Phase 6) will use field-level rules with a conflict log.

Not synced, by design: two-factor secrets and passkeys (each server has its own encryption key — set
up 2FA again on a branch server if you use its back office), remember-me tokens, device enrolment
codes (a till enrols on the server it talks to).

### How it works
- Every change writes an **event** in the same transaction (outbox), with a full snapshot of the record
  and a per-server sequence number.
- The branch server runs `php artisan erp:sync` every minute (scheduler): it **pushes** its own new events
  to HQ, then **pulls** HQ’s events after the last one it received. Batches of 500, gzip-compressed,
  each request signed with the pair’s shared secret (HMAC, 5-minute clock window).
- Received events are stored once (by event id) and then **applied**, **staged** or **held**, depending on
  the group’s mode. Applying is idempotent; an event that cannot be applied yet (e.g. the branch runs older
  code) is marked *failed*, raised in the Review queue, and retried at every sync until it applies — then
  its review item closes itself.

### Sync Center (*System → Sync Center*, needs “Run sync, manage sync peers…”)
- Status per peer: last sync, *to send / to review / held / failed* counts, last error.
- **Sync now** (branch side).
- **Choose what syncs** — per group: *Automatic*, *Manual (preview first)*, *Off (kept, not applied)*.
- **Review incoming** — counts per group and change type; apply or reject a group.
- **Export sync file (USB)** / **Import sync file** — the same events on a stick when there is no link;
  signed, applied once however many times imported.
- **Full resync from this peer** — receive everything again from the start (safe; repairs a damaged branch).
- **Add branch server** (HQ) and **Import till file**.

### Pairing a branch server
1. At HQ: *Sync Center → Add branch server* (or `php artisan erp:node:add-branch B01 "Branch A server" --as=<email>`).
   Note the command and the **secret** shown once.
2. On the branch server (`ERP_NODE_ROLE=branch`, `ERP_NODE_ID=B01` in `.env`, then `php artisan migrate --seed`):
   `php artisan erp:node:join https://hq.example.com <HQ node id> <company id>` and paste the secret.
   It downloads everything the branch needs, then the scheduler keeps it in step.

See `docs/install/branch-node.md` for the server itself.

---

## Troubleshooting

| Symptom | Check |
|---|---|
| Till says “working offline” but the server is up | Network/Wi-Fi to the server; the till pings `/up` every 15 s |
| Cashier cannot sign in offline | They must have signed in on *this* till online within the grant period |
| Till banner: items refused | Back office → Review queue (type *sync failed*) shows why; fix, then **Retry refused** on the till |
| Branch server: “Sync request time … off” | Server clocks differ by more than 5 minutes — fix NTP |
| Branch server: changes *failed* “Update this node” | Upgrade the branch server to the same version as HQ; they apply at the next sync |
| Many items *to review* | A group is in Manual mode; *Review incoming* |
| Suspect a branch is out of step | Sync Center → Full resync from this peer, then `php artisan erp:stock-check` |

### Tested scenarios (spec §4.3)
Two tills / HQ and branch selling the last unit offline · HQ price change while the branch sells at the
old price · duplicate replay (till batches and whole node resync) · partial batch failure · clock skew ·
till reinstalled mid-queue (receipt series + till file import) · 7 days offline then sync. *Online order
during a branch outage* needs the online store (Phase 4) and is tested there.
