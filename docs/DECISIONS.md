# Decision log

One line each: what — why. `[+]` = added beyond the spec.

## Phase 0 (2026-10-05)

- Owner answers: demo company (HQ=Wholesale, A=Grocery, B=Restaurant, C=Pharmacy); cloud HQ + branch nodes; single tenant, multi-tenant-ready schema; WooCommerce; Paystack + Hubtel MoMo test keys only; GRA E-VAT sandbox adapter; Claude API capped + Ollama fallback; neutral placeholder brand. Users/tills default: 15 users, 2 tills per branch.
- Monorepo `server/` (Laravel) + `pos/` (PWA); Tauri shell will live in `desktop/` (Phase 13) — one repo keeps API contracts and sync schemas versioned together.
- Laravel 13.34 / Filament 5.9 / Livewire 4.4 / Pest 4.7 — latest stable at scaffold time.
- PostgreSQL 17.11 installed from the EDB installer downloaded directly (signature verified) — `winget` download got HTTP 403.
- Dev uses Laravel Herd's PHP 8.4 (already installed); production nodes use FrankenPHP per spec.
- Queue/cache use the `database` driver in dev; Redis only where present (spec fallback).
- POS PWA precaches only the app shell; API responses are never cached by the service worker — sale data goes to IndexedDB (Phase 3), so stale cached prices can't be shown as live. [+]
- POS distinguishes "no network" from "network OK, server down" — different fixes for staff. [+]
- Reference build mined for: invariants table, permission matrix, offline grant/allocation contract, webhook signature rules (Shopify base64 HMAC-SHA256, Paystack HMAC-SHA512), outbox via `SKIP LOCKED`. These shape Phases 1–4.
- Offline auth follows the reference's stronger rule: enrolled device key + expiring signed grant, not a reusable cached password; a PIN only unlocks a grant. [+]
- Offline card/MoMo payments are recorded as *pending* unless a provider confirms; reconnect never re-charges. [+]
- Tests run against PostgreSQL (`erp_test`), not SQLite — SQLite can't prove locks or concurrency (lesson from reference build). [+]

## Phase 1 (2026-10-05)

- Base users migration rewritten to UUIDv7 before any real data existed (pre-release only; after go-live, schema changes are new migrations).
- Company + first owner are created by `php artisan erp:install`, not a public web page — no unauthenticated setup surface. [+]
- One `Recorder` service writes change + audit row + outbox event in one transaction; audit/outbox writes outside a transaction throw.
- Audit rows: HMAC-SHA256 chain per company, DB trigger blocks update/delete/truncate, secrets redacted, timestamps hashed at second precision (what the column round-trips).
- App DB role currently owns tables (could disable the trigger) → separate migration/runtime roles + off-server signed checkpoints in Phase 13.
- Authorization = level ceiling + role permission + scope path (company → legal entity → branch → warehouse → till) + numeric limits; missing scope never means global.
- Anti-escalation: no self-grant; granter must hold every permission of the role over that scope; role editors can only add permissions they hold company-wide; technical (IT) roles can hold only technical permissions. [+]
- Department and cost-centre scopes deferred to HR/Finance phases (no screens to protect yet).
- Seeder adds new permissions to default roles once; admin removals stick. Releasing a planned module = migration that flips `reserved` → `enabled`.
- Planned modules can only be *reserved* per branch, never "enabled" before they exist. Optional department modules mapped to phases: Storefront 4; Logistics, Assets, Projects, Helpdesk 12. Hospitality and Agriculture manifests deferred until scheduled.
- Two-factor per person (level ≥ setting, default L3) via our own middleware; Filament's switch is panel-wide. Authenticator app (TOTP + recovery codes) built in.
- Passkeys via spatie/laravel-passkeys 1.8 with overrides: user verification required, 32-byte challenges, inactive accounts refused, add/remove/sign-in audited. A passkey sign-in satisfies two-factor; merely owning one does not. [+]
- SimpleWebAuthn 13.3 browser build vendored in `server/public/vendor` (no CDN on admin pages).
- Profile edits and authenticator on/off go through audited services (Filament's default saves directly). [+]
- Till sign-in: username + PIN only on an enrolled device (device = second factor); 12-hour staff token bound to the device; revoking the device kills all its sessions; trivial PINs refused. [+]
- Device approval = admin registers it and gets a one-time, hashed, expiring enrolment code; the device enrols with code + public key.
- Setup wizard steps for tax profile, chart of accounts, hardware and sync arrive with those phases; users are added from the Users screen.
- Settings are code-declared definitions (label, help, range) with per-company values; the Settings page is generated from them. Only settings that something reads exist.
- API errors use `{"error","detail"}` with 401/403/422/423/429 mapping (from the reference build's convention).

## Phase 2 (2026-10-06)

- Catalog, price lists, tax and the stock ledger are **Core** (spec §6 lists them as core department modules). The *Retail & Wholesale* industry module moves to Phase 6, when wholesale customers, credit and B2B orders exist; tiered prices and multi-unit selling are already core.
- Money = integer minor units with a per-currency exponent (GHS 2, XOF 0); quantities = decimal(18,3) computed as integer thousandths; rounding half away from zero. The till runs an identical TypeScript copy, tested with the same cases. [+]
- Tax is configuration: rates (basis points) grouped into tax groups; per-line calculation, inclusive or exclusive lists, simple components (no tax-on-tax), proportional split with largest remainder. Ghana starter rates (VAT 15 / NHIL 2.5 / GETFund 2.5) are seeded **unverified**; an accountant check is recorded by name and cleared when the group's taxes change. COVID levy not seeded (believed abolished — accountant to confirm).
- Each sellable unit has its own price and barcodes; no derived carton prices. No fallback between price lists. Quantity tiers + scheduled (promotional) prices; dated beats open-ended within a tier.
- Starter data per company (units, tax, Retail/Wholesale lists) only fills empty collections — safe on every deploy. [+]
- Stock: append-only `stock_movements` (DB trigger) + `stock_levels` projection updated in the same transaction, rows locked in product-id order (no deadlocks between tills); `erp:stock-check` proves projection = ledger. Movements keep the entered unit/qty/factor as evidence.
- Negative stock: sales may go negative by default (shortfall flagged on the stock screen); setting can block it. Opening/adjustments never go negative. Opening balance once per product+warehouse; adjustments need a reason code. Approval of large adjustments arrives with counts (Phase 5).
- Base unit and unit factors are frozen once stock has moved (history would otherwise be reinterpreted).
- POS sale posting: till-generated UUIDv7 id is the idempotency key (same body → same result, changed body → 409); per-device receipt sequence `BRANCH-TILL-000123` kept on the till and floored by the server's last seen; server recomputes price (current), line totals, tax and totals and rejects any difference. Offline price-version acceptance arrives in Phase 3.
- Payments: cash (change), MoMo (network + transaction ID required) and standalone card (approval code); electronic tenders stored as `confirmed_by_cashier` until Paystack/Hubtel verification exists. Payments must equal the total.
- Shifts: one open shift per till (partial unique index); blind close — expected cash only shown after the count. Drops, pay-ins/outs and X/Z reports come with fuller POS work.
- Device enrolment by code only: first 4 characters stored as a lookup, whole code verified by hash. Device key = WebCrypto ECDSA P-256, non-extractable, kept in IndexedDB. [+]
- Till keeps unsent sales in IndexedDB with a "Send now" retry (safe because posting is idempotent); full offline selling is Phase 3.
- Receipt QR code deferred until something reads it (receipt lookup for returns); a QR with no consumer would be decoration.
- `origin_node` added to every table (Phase 1 rows backfilled with this node's id by migration).
- Sample data via `php artisan erp:demo-catalog --as=<email>` — runs as a named person so the audit log is truthful. [+]
- Filament display callbacks must name the value `$state` (values are injected by name); a regression test now checks rendered values on populated screens.

## Phase 3 (2026-10-07)

- Offline sign-in = signed offline grant issued at an online PIN sign-in (person + device + expiry, default 72 h, setting) with a PBKDF2-SHA256 (210k) PIN hash; the PIN unlocks the grant, no reusable password cached (reference-build rule). Grant signed with a key derived from APP_KEY.
- Offline sales are never refused for business reasons (old price, expired/revoked grant, clock skew, negative stock): recorded as they happened + review item. Refused only if not genuine (bad grant/signature) or internally inconsistent (arithmetic, missing shift). Online sales stay strict. [+]
- Till queue: ordered, FIFO; while anything is queued every action goes through the queue so the server sees work in order; sent in signed batches (device ECDSA key) every 15 s; items leave only on acknowledgement; "Retry refused" after a supervisor fixes the cause; signed "Export unsent sales" file importable in the Sync Center even for a revoked device. [+]
- Receipt series letter per device on a till (HQ-T01A-…): reinstalls/backup devices can never collide. Shifts are per device (index changed). Found while designing offline: the Phase 2 scheme would have collided on reinstall. [+]
- Catalog cached on the till with an ETag version (304 when unchanged); full snapshot rather than incremental deltas — enough for LAN-attached tills; incremental if a branch catalog outgrows it.
- Proxy 502/503/504 = server unreachable on the till (a reverse proxy in front of a stopped app server answers that way). Found in the browser test. [+]
- Node sync: every outbox event carries a full snapshot (row + owned children) and a sync group; receivers upsert idempotently (newer version wins), insert immutable rows once, move stock only for newly inserted movements. Node-local secrets (2FA, passkeys, remember tokens, enrolment codes) never travel; password/PIN hashes do (branch sign-in while HQ is down).
- Conflict policy "HQ wins" enforced at the source: branch servers refuse master-data writes (org, people, catalog, pricing) except node-local operational changes (PIN counters, own 2FA). So master data cannot conflict; sales/movements are append-only; negative stock after merge → review. Customers' field-level rule comes with CRM (Phase 6).
- Groups and direction fixed by role: down = org, people, catalog, pricing; up = sales (+devices, shifts), reviews; both = stock. Pull modes per group: auto / manual (staged, preview + accept/reject per group) / off (held, can be applied later). Pushes always send (sales must reach HQ). "Scheduled" = the every-minute scheduler; per-group schedules not added (no need found yet). Bandwidth/image skipping deferred until product images exist (Phase 4).
- Transport: HTTPS, HMAC-SHA256 per-pair secret, 5-min clock window, gzip both ways, batches of 500, cursors only move after acknowledgement; failed applies retried every sync and raised in the Review queue (auto-closed when they apply). USB sync files carry the same signature.
- Installer/seeder starter data and role catalog now publish outbox events (found by the two-node test: they bypassed the Recorder and never reached branches). [+]
- Tests run HQ and a branch server as two real PostgreSQL databases in one process (CI creates erp_test_node). [+]
- Playwright browser automation for the till not added yet (needs a browser download — owner to approve); offline flow verified manually in the in-app browser.
- "Online order during branch outage" (§4.3) moves to Phase 4 with the store connectors.


## Phase 4 (2026-10-08)

- Live updates: Laravel Reverb. The outbox is the only source: the relay (`erp:relay`, per-consumer cursors with SKIP LOCKED) turns committed events into broadcasts, store pushes and order intake. Nothing is broadcast from inside a transaction. [+]
- Live messages carry ids, not data (tills re-read through the permission-checked catalog API with ETag). Public shop channels carry public price/availability only. Private channels are tenant-checked; orders also need `orders.view`. [+]
- Live is a convenience, never a dependency: the till polls every 60 s when not live, the order list every 30 s, and shop pages render current figures on load. A failed broadcast is logged and skipped.
- Store connectors behind one interface (capabilities, credentials, push, verify webhook, normalise order, remote state). Built now: built-in shop, WooCommerce (REST v3), Shopify (GraphQL 2026-07), generic REST + HMAC webhooks. Marketplaces and delivery apps (Jumia, Glovo, Bolt Food, WhatsApp) are listed as planned and cannot be activated (spec: stubs, flag off).
- Connectors are tested with recorded request/response fixtures only (Http::fake). No real store accounts were created (spec rule). WooCommerce/Shopify credentials are the owner's to enter.
- Pushes are coalesced jobs (one pending per product and kind), skipped when nothing changed (hash), retried with exponential backoff and jitter, and dead-lettered after 8 attempts with a review item. Reconciliation every 15 min repairs price/stock drift.
- Webhooks: signature checked over the raw body, stored before processing (2 MB max), deduplicated by delivery id, processed by the relay. Invalid signatures are not stored.
- Availability offered online = on hand − active reservations − per-channel safety stock, floored at zero. Online orders reserve stock on arrival and issue it through the ledger only at hand-over. Oversold orders are accepted and flagged (never lost); store total ≠ items total is flagged. [+]
- Online orders and reservations live at head office (where the stores connect) and are not replicated to branch servers yet. The safety stock absorbs offline branch sales during an outage (§4.3 test). Branch-side order handling over sync is deferred until a branch needs it.
- Payment for online orders: pay at pickup (cash / MoMo / card recorded at hand-over) or already paid in the store. Gateway payments (Paystack/Hubtel) come with the payments work. Customers are not notified automatically yet (SMS/WhatsApp come later).
- Built-in shop = the Storefront module, released in this phase and enabled per branch. Turning it off closes the shop (404). It provides catalogue, live prices/availability, an order (cart), and pickup checkout. Prices are always server-side. CSRF, rate limits, a honeypot and a one-time order token protect checkout; the confirmation is a signed link. Shop texts live on the channel (nothing hard-coded); addresses are unique across companies. [+]
- Shopify order fulfilment status is not pushed yet (capability gap noted on the connector). WooCommerce order completion is pushed.
- Reverb key/secret are per installation (`reverb:install`) and never committed. The key is public (sent to tills and shops); the secret stays on the server. Production needs TLS on the socket (`REVERB_SCHEME=https`).
- Deferred omnichannel extras: click-and-collect slots, delivery zones/fees, buy-online-return-in-store, and per-branch shop pages for several branches in one shop.

## Phase 5 (2026-10-09)

Owner answers (2026-10-09):
- Valuation: **weighted average** per product per warehouse (FIFO possible later, before any sale is costed under it).
- PO approval: **tiered** default limits — Purchasing Officer ≤ GHS 5,000, Branch/Warehouse Manager ≤ GHS 20,000, Finance Manager/GM above; creator never approves own PO. Editable per person.
- Bins: **per-warehouse switch**, off by default (on for the HQ wholesale warehouse in demo data).
- Over-receipt: allowed up to **5%**; more needs a manager's approval on the GRN.

Design (Phase 5):
- Purchasing and the warehouse (WMS) are Core (spec §6 "installed with Core"). Their permissions are in Core 0.5.0 and their screens sit under Purchasing and Stock.
- Document numbers: one gap-free sequence per node and type (`PO-HQ-000001`, `GRN-B01-000007`), taken inside the document transaction. Server documents never collide across nodes; till receipts keep their per-device series. [+]
- Default approval limits travel with their permission. A limit key (e.g. `po_approve_minor`) is added to roles and existing grants only when its permission is first introduced, so later seeding never overwrites owner edits; this was tested as an upgrade. Purchasing Officer approval (≤ GHS 5,000) needs `purchasing.approve` at level 2, and `suppliers.manage` is level 2 too. [+]
- GRNs are created held and then posted: the first audited change is held → posted, so every GRN has an audit row and a snapshot with its lines. A delivery above tolerance stays held, puts nothing into stock, and raises a review item (`grn_held`). Without a purchase order, only a manager can receive. Handheld receipts carry a client UUID, so a resend never receives twice.
- Valuation is a weighted average per base unit, kept on `stock_levels.avg_cost_minor` (4 decimals) and moved by a shared projection that the ledger and sync both use. Stock below zero counts as zero when averaging. Incoming stock without a cost (count gains, put-away) leaves the average alone. Outgoing movements are stamped with the average (the basis for cost of goods sold in Phase 7). Each node computes its own average from the movements it has, so nodes may differ slightly when movements arrive in a different order; head office's figure is authoritative for finance. [+]
- Bins are switched on per warehouse (owner). With bins on:
  - every movement names a bin;
  - incoming stock with no bin goes to the receiving bin;
  - outgoing stock with no bin is taken in pick order (storage before receiving; whole cartons are not split across bins where possible).
  `erp:stock-check` now also proves bin levels equal their movements, and that a bin-managed warehouse equals the sum of its bins. That second check was added after a bug: switching bins on posted the placement into RECV without its "out of no bin" leg. It was found in the browser demo, fixed, tested, and the dev data corrected with append-only compensating movements. [+]
- Transfers: dispatch posts `transfer_out` at the sender's average cost; the goods are in transit (in neither warehouse) until receipt posts `transfer_in` at that cost. Differences are flagged (`transfer_short`), never absorbed. Only drafts can be cancelled.
- Counts:
  - expected quantities are frozen at the start, and posting books counted − expected as `count` movements;
  - uncounted lines are left alone;
  - blind by default;
  - the poster must differ from the submitter;
  - count movements may take stock below zero (they record reality).
- Reordering is min/max per warehouse. It counts on hand + open purchase orders + transfers in transit, and suggests whole purchase units of the preferred supplier (at least their minimum). Drafts are created per supplier and are never sent automatically.
- The handheld is a mode of the same PWA, chosen by the device kind returned at enrolment, under its own API (`/api/v1/wms`) and token ability (`handheld`). It works online against its branch's server over the LAN; no offline queue for warehouse work yet. Hardware scanners act as keyboards (scan, then Enter); camera scanning is deferred. [+]
- Head office relays the two-way sync groups (stock, purchasing, warehouse): events applied from one branch server are published again at HQ and served to every other branch server, but never back to their origin. This fixes transfers between two branch servers, and it lets HQ's online-store stock sync and live updates see branch sales. Sales documents still flow up only. [+]
- Deferred: requisitions/RFQs, 3-way match and supplier invoices (with Finance, Phase 7), returns to supplier, supplier scorecards and portal, landed costs, FEFO/batches/expiry (Pharmacy/Grocery, Phase 9), pick/pack/ship waves, ABC analysis, and printing PO PDFs and bin labels.

## Phase 6 (2026-10-10)

Owner answers (2026-10-10):
- Credit limit / overdue: **hold for approval**. Orders over the limit go on credit hold and a manager with the override right releases them within their limit; on the till, a credit sale over the limit needs a supervisor PIN.
- Wholesale invoices are raised **when goods are delivered** (posting a delivery note creates its invoice).
- Loyalty default **1 % back**: 1 point per GHS 1, 100 points = GHS 1, redeem from 500, expire after 12 months (built in 6b).
- Customers sync to **all branches**: field-level last-writer-wins with a conflict log; credit limits set at HQ only.
- Phase 6 ships as two PRs: 6a, customers, order-to-cash, receivables and credit on the till (the done-when); 6b, promotions, loyalty and gift cards. The engine for 6b must behave the same in PHP and the offline till.

Design (Phase 6a):
- **Receivables sub-ledger.** `customer_ledger` is append-only (DB trigger); + means the customer owes more, − means less.
  - Entries come from invoices, till credit sales, payments, credit notes and opening balances.
  - `customer_balances` is a projection, moved in the same transaction.
  - `receivable_allocations` records which documents a payment or credit note settles.
  - Unique per (document, type), so posting twice is impossible. Sync sends entries and allocations as raw batches, applied idempotently. Finance (Phase 7) will post from here. [+]
- **Settlement:** payments settle the oldest items first (or chosen invoices). Leftover credit is applied automatically when a new invoice or till credit sale arrives. Invoices never change; a credit note clears what is open and leaves any paid amount as account credit. Partial credit notes and refunds of account credit are deferred.
- **Credit check:**
  - exposure = balance + undelivered order value + the new amount;
  - overdue debt or a manual hold also triggers a hold.
  - An override limit is measured against the amount over the limit (all of it when there is no credit account, a hold or overdue debt). A supervisor's GHS 1,000 thus approves a sale that is GHS 230 over, not one GHS 1,550 over.
  - Default override limits:

    | Role | Limit |
    |---|---|
    | Supervisor | GHS 1,000 |
    | Branch Manager | GHS 20,000 |
    | Finance / GM / Owner | Unlimited |

  - The releaser must not be the creator. [+]
- **One document from quote to order:** `quote_number` while a quotation, `number` once confirmed, so the quote → order link needs no join table. Prices are resolved from the customer's price list when the quote is saved; manual price changes on quotes wait for discount rules (6b).
- **Delivery and invoicing:** one invoice per delivery, using the order's tax rates on the quantity delivered. A delivery cannot take stock below zero (it is not an offline sale).
- **Till credit sales:**
  - The sale's `customer_id` is part of its idempotency hash.
  - The `account` tender posts a `pos_sale` ledger entry, due after the customer's terms.
  - Supervisor approval is a reusable `SupervisorApproval` (username + PIN, same lockout, permission + limit, not the cashier). It is only sent online and never stored in the offline queue.
  - Offline credit sales follow the Phase 3 rule: kept, then flagged. [+]
- **Customer sync** is field-level last-writer-wins. Each field stamp holds `at` (time|node) and `base` (the stamp the editor had seen); a conflict is logged only when an edit was not built on the other side's change. This gives a real conflict log without vector clocks. HQ ignores incoming credit fields, and branch servers refuse to change them. [+]
- **Fresh installs:** the first owner's grant now takes the owner role's default limits. Before this, a new owner had no PO-approval or credit-override limit; existing installs got them through the upgrade path. This was found by the screens test. [+]
- **A4 documents** are Blade pages (print or "Save as PDF") under `/print/*`. They need a signed-in back-office user, check permissions per document, and check the tenant explicitly. Server-side PDF and emailing come with notifications later.
- **Filament note:** search callbacks must name their argument `$search`; a `$s` slipped through tests that fill fields by id and was found in the browser demo.
