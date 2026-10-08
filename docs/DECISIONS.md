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

## Phase 6b (2026-10-11)

Owner answers (2026-10-11):
- **Promotions:** the **best single promotion per item**; basket offers (spend X) and coupons apply on top.
- **Manual discounts and price overrides (default limits):** cashier 0 %, supervisor 10 %, branch manager 25 %; above your own limit → supervisor PIN.
- **Returns:** **30 days with the receipt**, refunded to the original tender (account sales credited to the account); without a receipt, store credit only, with a supervisor PIN.
- **Gift cards and store credit:** expire **12 months after last use**.

Design:
- **One engine, two languages.** `App\Domain\Pricing\PromotionEngine` (PHP) and `pos/src/promotions.ts` (TypeScript) implement the same integer algorithm; `spec/vectors/promotions.json` is run by both test suites, so a change to one that is not made to the other fails CI. Order: best item promotion per line (percent, amount off, buy X get Y, bundle / mix & match) → manual line discount → best spend-X offer → one coupon → sale discount. Group savings (buy X get Y, bundles) are spread over every unit in the group by price, so "best per item" compares like with like. Rounding: largest remainder, never floats.
- **The server is the judge online, the till's figures stand offline.** An online sale is recomputed with the promotions active at its branch and time; a different total is refused (`totals_mismatch`, the till refreshes). An offline sale keeps the till's totals when they are plausible (never above list price) and raises `offline_price` for review — the customer already paid.
- **Manual discounts use the existing limits.** `pos.discount` with the `discount_bp` limit (supervisor 10 %, branch manager 25 %, GM/owner 100 %). Over your own limit a supervisor approves with their PIN; offline, the PIN is checked against the supervisor's offline sign-in on that till and recorded as `discount_approved_offline_by` (over their limit → `discount_over_limit` review item).
- **Loyalty and stored value are append-only ledgers** with balance projections and the same DB trigger as stock and customer ledgers. 1 point = 1 pesewa when spent; 1 point earned per GHS 1 on cash, MoMo, card and account (not on points, gift cards or store credit). Both sync in the `customers` group as batches and are relayed between branches; overdrawing offline is kept and flagged (`points_overdrawn`, `stored_value_overdrawn`), never refused after the fact.
- **Returns are sales of kind `return`** with negative amounts and `original_sale_id`, so stock, shift cash, tax and reports need no second code path. The value refunded is what the customer actually paid for each line (after promotions), refunded to the original tenders in order; points earned on the returned value are taken back. Returns need the server (they must see the original sale); offline returns are deferred.
- **Expiry** runs only at head office (`erp:expire-balances`, daily 02:30) and posts `expire` ledger rows, which then sync like any other entry.
- **Deferred:** selling gift cards at the till (back office only for now), offline returns, partial credit notes / refunds of account credit, sales reps and commissions.

## Phase 7a (2026-10-12)

Owner answers (2026-10-12):
- **Chart of accounts:** the Ghana SME template (IFRS for SMEs layout); financial year **January–December**.
- **Opening balances:** the accountant enters them (opening trial balance, open customer and supplier items, stock at cost) on the go-live date.
- **Supplier bills:** **3-way match** (order price, goods received, bill); a difference above **2 % or GHS 50, whichever is smaller**, waits for a finance manager.
- **Month-end:** **monthly lock**; finance closes each month once checks pass; only the **GM/owner reopens**, with a reason; late documents post to the next open month.

Design:
- **Finance lives at head office.** Accounts, months, journals and payables do not replicate; branch servers keep feeding the sub-ledgers (sales, stock, customers) as before and HQ posts them.
- **Automatic posting is a catch-up job, exactly once.** `AutoPoster` (every 5 minutes, `erp:gl-post`, and "Post now") takes every source row not yet in `gl_postings` (primary key = source type + id), so rows that sync late or out of order are simply picked up on the next run. High-volume sources (till sales and returns, stock movements, points) are summarised per branch and day — one entry, many sources linked; documents (invoices, credit notes, payments, bills, gift-card sales, shift over/short) get one entry each.
- **The ledger is enforced by the database too:** journal tables are append-only (trigger), an entry must balance and have two lines (deferred constraint trigger, checked at commit) and may only go into an open month (trigger). Mistakes are reversed by an equal and opposite entry.
- **Control accounts belong to their sub-ledgers** (customers, suppliers, stock, gift cards/store credit, loyalty points): journals may not touch them, so `erp:gl-check` can prove each equals its sub-ledger. This is the Phase 7 done-when check, also shown under Finance → Reports → Ledger check and required before a month can close.
- **Stock at cost:** the stock sub-ledger is the sum of movement values (quantity × the cost stamped on each movement; bin put-aways excluded). Incoming stock without a cost of its own (returns, count gains) is now stamped at the current average, and a goods-received reversal takes goods out at the cost they came in at, so goods-received-not-billed clears exactly. Weighted-average rounding can make on-hand × average differ by pesewas from the sub-ledger; the ledger follows the movements.
- **Purchases:** goods received post Dr Stock / Cr Goods received not billed at the receipt cost; the bill clears that at the same value and puts any price difference to Purchase price differences. Recoverable input tax (per tax rate, `input_recoverable`, default yes — accountant to confirm) goes to input tax accounts; non-recoverable tax is a cost.
- **Till sales:** revenue net of tax; each tax code to its own payable account; each tender to its own account (cash in tills, MoMo, card settlements, customer accounts, loyalty points, gift cards). Points are a cost when earned (1 point = 1 pesewa) and a debt until spent or expired; expired gift-card balances are income.
- **Cost centres:** every line carries the branch. Departments come later.
- **Years:** no closing entries; reports start income and expense accounts at zero each year and show earlier years' profit in retained earnings.
- **Segregation:** accountants enter bills and journals; finance managers close months, approve price differences and pay suppliers; only the GM/owner reopens a month; IT admin has none of this.
- **Deferred to 7b:** bank and MoMo statement import and reconciliation, expenses/petty cash, budgets, fixed assets and depreciation, multi-currency revaluation, VAT return and tax reports, supplier credit notes / returns to supplier, GRA E-VAT.

## Phase 7b (2026-10-13)

Owner answers (2026-10-13):
- **Statements:** **CSV import now, bank APIs later** — each bank/MoMo account remembers its column mapping; `bank_accounts.feed` marks the source so an API feed can later produce the same statement lines.
- **Depreciation:** **straight-line, monthly**; default classes Furniture 60, Equipment and computers 36, Motor vehicles 60 months (editable).
- **Budgets:** **branch × income/expense account × month** of the financial year.
- **Expenses:** **claims with receipt photo and a petty cash float per branch**; branch manager approves up to GHS 500 (limit `expense_approve_minor`), finance above; never your own.

Design:
- **Reconciliation** matches statement lines to unmatched ledger lines on the bank's account, many-to-many when the totals agree (one deposit for several days' takings; one day's MoMo sales against many wallet credits). Suggestions are 1:1 (same amount, ±5 days, unique both ways). Lines with nothing behind them (charges, interest, fees) are posted from the statement and matched in one step; ledger lines older than the first imported statement (e.g. the opening balance) are cleared as "on an earlier statement". Re-importing a file skips lines already in (hash of date, amount, text, balance and position among identical lines). The reconciliation shows ledger − outstanding + unrecorded = what the statement should show, against the statement's own balance column.
- **Money moved** between own accounts (till cash banked, petty cash topped up, MoMo swept) is a document posted by the automatic poster; each side keeps its own branch.
- **Expense claims post when paid** (Dr the expense, Cr petty cash or the account paid from); an approved but unpaid claim is not accrued. Receipt photos are private files served only to the claimant, approvers, payers and finance.
- **Fixed assets:** registering does not post the cost (it is already in the books through a bill or the opening balances). Depreciation is calculated for every month that has ended (and the disposal month) at the start of each posting run — one entry per month; each month charges (cost − residual) ÷ life, the last month takes the rounding. Assets brought over with depreciation in the opening balances start from the month they were registered. Disposal takes cost and depreciation off and posts the gain or loss.
- **VAT return** figures come from the ledger (output and input tax per tax code) with sales split into taxed and untaxed from the document lines; the accountant verifies against the GRA return before filing.
- **New purposes in the chart** (petty cash, accumulated depreciation, depreciation, disposal gain/loss, bank charges) reach existing companies through a one-time migration backfill (`ChartTemplate::ensure`), which never renames or moves anything.
- **Deferred:** direct bank/MoMo API feeds, supplier credit notes and returns to suppliers, foreign-currency revaluation, GRA E-VAT fiscal adapter (planned with the integrations phase), petty cash counts.

## Phase 8 (2026-10-14)

Owner answers (2026-10-14):
- **Pay:** **monthly**, net pay by **bank, MoMo or cash** (signed pay sheet for cash; CSV list for bank/MoMo bulk payment).
- **Pay items:** **transport and meal allowances, overtime, staff loans and salary advances, provident fund (Tier 3) and welfare dues** (besides SSNIT and PAYE).
- **Attendance:** **clock in/out on the till with the staff PIN** (works offline); managers correct with a reason. Biometric devices later.
- **Leave:** **Ghana statutory defaults** — annual 15 working days, sick (doctor's note), maternity 12 weeks (60 working days), paternity 5 days, unpaid; approved by the line manager.

Design:
- **Statutory profile, not code:** PAYE bands (monthly widths, basis points), SSNIT rates and Tier 1/2 split, Tier 3 relief cap, junior-staff overtime rates and the standard day live in `payroll_profiles.rules` (seeded GH-2024: 0 % on the first GHS 490, then 5, 10, 17.5, 25, 30, 35 %; SSNIT 5.5 % + 13 %, Tier 1 13.5 %, Tier 2 5 %). Payroll shows "NOT YET CHECKED" until someone with payroll.approve confirms the rules (recorded with their name). Changing rules is a new confirmation, audited.
- **Calculator is pure integer maths** (`PayrollCalculator`), unit-tested on hand-worked figures. Tier 2 is computed as the remainder of the 18.5 % so the split always adds up. Qualifying junior staff overtime is taxed separately (5 % up to 50 % of basic, 10 % above) and kept out of PAYE income; otherwise overtime is ordinary taxable pay. Allowances are taxable. Unpaid leave and days outside employment are docked at basic ÷ working days (Mon–Fri). Loan repayments never take net pay below zero.
- **Run lifecycle:** draft (recalculable from employees, timesheets, approved unpaid leave and outstanding loans) → approved by someone other than the preparer (payslips locked; loan repayments recorded; the automatic poster books wages, employer SSNIT, PAYE, SSNIT Tier 1, Tier 2, Tier 3, welfare, staff loans and salaries payable) → paid (clears salaries payable from the bank and cash accounts chosen). Statutory remittances (GRA, SSNIT, trustees) are paid with supplier payments or journals for now.
- **Attendance events are append-only** (DB trigger); corrections add a manual event or a voiding event, each with a reason. Clock-ins are by user (the person's sign-in); employees link to users, so branch servers need no HR data. Offline clocking is verified against the person's offline sign-in on that till and queued as a `clock` event signed by the device; branch-server events flow up in the `attendance` sync group. Overtime = time beyond the standard day per day; a clock-in without a clock-out counts nothing and is shown to the manager.
- **Privacy:** pay and bank details only with hr.manage / payroll permissions; hr.view sees people without pay; payslips are visible to the person (self-service page) and payroll staff; leave documents and receipts are private files.
- **Segregation:** HR manager hires, prepares payroll and gives advances; finance manager (or GM) approves payroll and confirms the rules; finance pays. Nobody approves their own leave or payroll.
- **Deferred:** biometric/geofenced clocking, shift rosters, public holidays in working-day counts, casual daily-rate pay, statutory returns (SSNIT C-form, GRA PAYE schedule) as files, performance and recruitment, mid-year profile changes by date within a run.

## Licensing and anti-piracy (2026-10-15)

Owner answers (2026-10-15):
- **Activation:** an **offline signed key plus a daily online check-in** with a Synclytics licence server.
- **Expiry:** warn 30 days before, **14-day grace**, then **read-only — no printing, nothing output at all**.
- **Key limits:** branches, tills/devices, active users, modules (industry modules; Finance and HR as licensed features).
- **Protection:** hidden watermarks, tamper detection, per-customer build stamping (not ionCube for now).

Design:
- **Ed25519 over canonical JSON** (sodium): licence keys `SYN1-<payload>.<sig>`, check-in answers, release manifests. The production public keys are in `config/license.php` (code, not `.env`, so they cannot be swapped). The development key is honoured only in development builds (no signed release manifest).
- **One choke point:** the Recorder (every change goes through it) refuses when the licence is read-only. Installing, activating, joining a branch server to HQ and applying sync are exempt (they only move what was already recorded, or bring the licence itself). Printing and downloads have their own middleware. The till sync endpoint answers 423 for the whole batch, so tills keep their queue instead of marking sales refused. Never delete or hide data.
- **Feature licensing in the Authorizer:** permissions of unlicensed features (finance.*, payables.*, banking.*, … / hr.*, payroll.*, …) are never granted, so menus, screens and services all follow from one rule.
- **Binding:** installation id (random, per database) + machine fingerprint (OS machine id + PostgreSQL system identifier). A mismatched installation id is read-only at once; a changed machine gets the grace period to re-activate (hardware is replaced legitimately). Branch servers take the licence from HQ by sync (`org` group) and check expiry, revocation and tampering, but not binding or check-in.
- **Check-in:** HQ daily (`license:checkin`, 03:15): the report includes counts, build id and tamper state. The signed answer can revoke or hand over a renewal (`--replaces`). Not reaching the server: warning after `checkin_days` (30), read-only after another grace period. A forged answer is ignored.
- **Tamper detection:** `release:build` signs the hashes of the licensing-enforcing files. In a release, a changed, missing or added guarded file → read-only + reported. Honest limit: someone with the source can remove checks; the layers raise the cost and leave evidence.
- **Watermarks:** licence short id + build id, as visible micro-text and zero-width characters (U+200B/U+200C between U+2060 marks) in printed documents. Also a meta tag, the till receipt "Ref" line, a build stamp in the till bundle (`data-build`), and a stamp in every PHP file of a release. `license:trace` decodes them.
- **Deferred:** a vendor web console for the licence server (artisan commands for now), ionCube encoding, hardware dongles.

## Phase 9a — Grocery and batch/expiry (2026-10-16)

Owner answers (2026-10-16):
- **Scales:** label scales print EAN-13 with the **weight in the barcode** (prefix · 5-digit PLU · grams · check digit; prefix 21 by default).
- **Near expiry:** **FEFO + suggested markdowns** a manager applies (default 30 % at ≤ 7 days, 50 % at ≤ 3 days); expired batches cannot be sold and are written off as shrink.
- **Pharmacy (9b):** pharmacist approves prescription-only sales (PIN), prescriptions recorded, controlled-drug register, warnings from a local table.
- **Restaurant (9c):** both table service and counter service, kitchen screens.
- Phase 9 is delivered in three parts: 9a Grocery (with batch/expiry in the core stock ledger), 9b Pharmacy, 9c Restaurant.

Design:
- **Batches live in the core stock ledger** (Pharmacy needs them too): a product flag `track_batches`; every movement of such a product names a batch; `stock_batch_levels` is a projection checked by `erp:stock-check`. A batch id is a UUID v5 of (company, product, batch number), so head office and branch servers agree without coordination; batches sync in the `stock` group.
- **Which batch:** stock going out without a batch is taken earliest expiry first. Sales and deliveries never use expired batches online — refused with `expired_stock`. An offline sale already happened, so it is kept and flagged `expired_sold`. Overselling lands negative on the newest batch.
  - Stock coming in without a batch joins the newest batch. Receipts and opening stock must name theirs, and an expired batch is refused at receiving.
  - Transfers carry the batches that were sent; returns go back to the batch they were sold from; GRN reversals take the batch received.
  - Moves between bins carry no batch (they do not change what a warehouse holds).
  - Turning tracking on for a product with stock posts a value-neutral `batch_assign` pair into batch OPENING, excluded from the ledger like put-aways.
- **Markdowns are stickers, not price changes:** applying a markdown creates an EAN-13 code (prefix 29) tied to one batch with its own price. The till charges that price, the stock comes off that batch, and the line stays out of promotions (engine product id `markdown:<id>` in both engines). Markdowns sync down in the `pricing` group; a withdrawn or expired sticker is refused online.
- **Scale labels are read on the till only** (the server receives an ordinary weighed line); PLUs are unique per company.
- **Shrink** = negative adjustments and count shortages at cost, by reason (new reason "Spoilage").
- **ESL hook:** a separate outbox consumer (`esl`) posts shelf prices to the configured gateway on product/price changes. A failing gateway keeps its own cursor and retries without holding up tills or online stores.
- **Grocery is a per-branch module** (module 0.1.0, permission `grocery.markdowns`): tills get scale and sticker settings only where it is enabled. Batch tracking itself is core.
- **Known limits:** bins and batches are tracked separately (which batch sits in which bin is not recorded); counts adjust totals (gains join the newest batch, shortages FEFO).

## Phase 9b — Pharmacy (2026-10-17)

Owner answer (2026-10-16): a **pharmacist approves every prescription-only sale with their PIN**. Prescriptions are recorded, there is a controlled-drug register, and warnings come from a local table.

Design:
- **Medicines are products** with a schedule (`otc`, `pom`, `controlled`) and drug fields. A medicine must track batches (`batch_required`), so FEFO, expiry refusal and the register come from the 9a batch ledger.
- **The rule lives in `SaleService`, per branch.** It applies when the Pharmacy module is on at the till's branch and a line is POM or controlled. Online sales without the patient or prescription are refused (`prescription_required`).
  - Approval is a supervisor-style PIN check of `pharmacy.dispense` at the branch. The cashier may be the pharmacist, so no "someone else" rule applies.
  - If the cashier holds `pharmacy.dispense`, no PIN is needed.
  - Offline, the till checks the pharmacist's PIN against their offline sign-in and sends `pharmacist_offline_by`. The server re-checks the permission. Anything missing becomes a `pharmacy_unapproved` review item; the sale is never refused.
  - An offline sale without a patient creates no prescription (there is no one to file it under); the review item records it.
- **A prescription is an immutable document** (RX number per node), created in the sale's transaction. It has an audit row and the outbox event `prescription.dispensed`, syncs up in the `sales` group, and is linked from its sale lines (`sale_lines.prescription_id`). It stores the warnings the pharmacist saw.
- **Warnings: same rule on the till (`pos/src/pharmacy.ts`) and server (`PharmacyService::warnings`).**
  - An allergy is any active ingredient or generic name that matches the patient's allergies; it is always major.
  - An interaction is any pair of different products whose ingredients match a table row, in either order.
  - Ingredients are lower-cased. Table pairs are stored sorted, one row per pair.
  - The table is local (`source` = `local`), so a licensed data provider can fill it later. It syncs down in the `pricing` group. Rules are never deleted.
- **Health data is need-to-know:** allergies and NHIS numbers reach only tills signed in by holders of `pharmacy.patients` or `pharmacy.dispense`. Patient profiles sync in the `customers` group.
- **Controlled-drug register = a query, not a second ledger.** It reads `stock_movements` of controlled products joined to sales, prescriptions and patients, with a running balance. It cannot drift from the stock, and the ledger is already append-only. The NHIS claims export likewise reads prescription lines of patients with an NHIS number.
- **Roles:** Pharmacist (level 4: dispense, patients, manage) and Pharmacy Technician (level 2: till, patients, receiving). The GM gets `pharmacy.manage` and `pharmacy.patients`; a Branch Manager gets `pharmacy.patients`.
- **Deferred:** an NHIS e-claims API (CSV for now), a commercial interaction database, dose and age checks, label printing for dispensed medicines, refills or partial dispensing of one prescription.

## Phase 9c — Restaurant (2026-10-18)

Owner answers (2026-10-17):
- **Service:** both table service and counter service.
- **Kitchen:** kitchen screens, with a ticket printer where there is no screen.
- **Money:** tips plus an optional service charge; tips paid out through payroll.
- **Recipes:** ingredients deducted when the bill is paid.
- **Also wanted:** reservations, delivery-aggregator orders, a kitchen waste log and bar tabs. Bar tabs are in 9c. The other three follow in **9d**.

Design:
- **Bills live on the server.** A dining order is shared by tills, waiter tablets and kitchen screens. Its id and item ids are generated on the device, so retries are idempotent.
  - At most one open bill per table (a partial unique index).
  - Changes go through the Recorder (audit row + outbox; `dining_order` syncs up in the `sales` group, items as its children). A live notice goes on `tenant.{id}.dining.{branch}`.
  - Without the server there is no shared table state, so table service needs the server. A **branch server on the LAN** keeps it working when the internet is down. Counter sales stay offline-capable.
- **Items** are sent when the waiter presses Send.
  - Each goes straight to its station, or is **held** until its course is called.
  - Statuses: held → fired → ready → served, or void.
  - Voiding something the kitchen has needs `restaurant.void` or a supervisor PIN, plus a reason. Paid items cannot be voided (refund instead).
- **Paying reuses the till sale.** A sale carries `dining_order_id`, and its lines carry `dining_item_id`.
  - The server checks that each dish is paid **whole**, once, with exactly its **charged extras**. Splitting a bill = several sales over different items. Splitting evenly = several tenders on one sale, so the tax receipt stays one document.
  - When nothing unpaid is left, the bill closes and the table is free.
  - Online conflicts are refused (`bill_changed`). Offline they are kept and flagged (`dining_conflict`).
- **Modifiers:** a charged choice is a **product**. It becomes its own sale line (with the dish's item id), so price lists, tax and promotions need no new rules. Free choices are a kitchen note (`sale_lines.note`).
- **Service charge = one line of a configured non-stock product** (`restaurant.service_charge_sku`), so the tax comes from its tax group.
  - The rate (`restaurant.service_charge_bp`) applies to the bill after discounts: gross on tax-inclusive lists, net otherwise.
  - The line stays outside promotions. The server recomputes it and refuses a different figure online.
  - The till adds it for table and tab bills; the cashier may remove it.
- **Tips** = `sales.tip_minor` paid on top of the total (payments add up to total + tip). The tip goes to the bill's waiter, or the cashier for counter sales.
  - In the books: credited to **2260 Tips owed to staff** (new system account `tips_payable`, back-filled for existing companies by migration).
  - Payroll adds the month's tips as a taxable **TIPS** earning. The payroll entry debits Tips owed instead of salaries for that part.
- **Recipes:** `recipe_lines` per dish (quantity per base unit, in the ingredient's unit). When the sale posts, a dish with a recipe moves its ingredients instead of itself; other lines move as before. Returns do not restore ingredients, and voids never touched them.
- **Counter orders:** an online sale at a restaurant branch with station products creates a closed `counter` order (fired items, linked to the sale), so the kitchen screens show it. The receipt prints its order number.
  - An offline sale does not create one later (it would reach the kitchen hours late). The till prints a kitchen ticket at the time instead.
- **Kitchen screens** authenticate as the device (kind `kds`, device token); nobody signs in. They poll every 4 s (`throttle:kds`), so they work without the websocket server. A station marked "print tickets" makes its device print each new dish (printer fallback).
- **Set-up data** (stations, tables, modifier groups, recipes) is head-office master data in the `catalog` group.
- **Roles:**
  - Waiter / Bartender (level 2): `restaurant.serve`.
  - Kitchen Staff (level 1): attendance and leave only.
  - Supervisor: serve and void.
  - Branch Manager and GM: also `restaurant.manage`.
- **Deferred to 9d:** reservations, the delivery-aggregator hook (Bolt Food / Glovo / Chowdeck), the kitchen waste log.
- **Deferred further:** merging and transferring items between bills, a graphical floor-plan editor (grid positions for now), recipe costing reports.

## Phase 9d — Restaurant extras (2026-10-19)

Owner answers (2026-10-18):
- **Bookings:** an optional deposit and an SMS reminder.
- **Delivery apps:** a webhook hook plus manual entry at the till.
- **Kitchen waste:** logged by kitchen staff; a manager approves above a limit.

Design:
- **Bookings** are documents (`reservations`, RES numbers). They sync in the `customers` group, both ways and relayed, since bookings are made at the branch or at head office.
  - A table cannot be double-booked: overlapping `starts_at` + `duration` on the same table is refused (`table_booked`).
  - The floor shows a booking from `restaurant.hold_minutes` before its time, and late after 15 minutes. Releasing the table is a person's decision (No-show), never automatic.
  - Seating opens the bill on the table, linked both ways.
- **Deposits** are money at the till, recorded in their own append-only table (`deposit_movements`: taken, refunded, forfeited):
  - they count in the shift's expected cash;
  - they post to the books via a new AutoPoster kind (Dr money / Cr 2330 Customer deposits; refund reversed; forfeit Dr deposits / Cr 4800 Other income).
  - Using the deposit on the bill is a till tender `deposit`, so it appears on the receipt and closes the liability through the normal sales posting.
  - Partial use is allowed; the rest stays held (refund or use later).
  - Refunds need `restaurant.void` or a supervisor PIN. Keeping a deposit needs `restaurant.manage` and a reason.
  - The new system keys `customer_deposits`, `other_income` and `aggregator_receivable` are back-filled by migration.
- **SMS** is a hook, not a provider: an outbox consumer `sms` posts `{to, message, reference}` to `restaurant.sms_webhook_url`. A failing gateway is retried without blocking anything else.
  - `erp:reservation-reminders` (every 5 minutes) marks due bookings `reminder_sent_at` and publishes `reservation.reminder`. Bookings made less than an hour ago are not reminded, and changing the time resets the reminder.
  - Messages are transactional (only for the guest's own booking). Nothing is sent without the gateway address and a phone number.
- **Delivery apps** reuse the sales-channel webhook pipeline (signature check, store once, relay, review on failure) with a `DeliveryConnector`.
  - It accepts orders in the documented signed format, the same as the REST channel. Today these platforms give merchant API access through partners, so a bridge maps their payload to ours. No product or stock is pushed.
  - Orders become `delivery` bills (platform + order number unique per company) whose items are fired at once.
  - The same bill can be keyed in at the till.
  - Hand-over is a till sale paid by tender `aggregator` → 1240 Due from delivery apps. The platform's payout and commission are booked when reconciling the bank.
  - No service charge or tip on delivery bills.
- **Kitchen waste** goes through the stock ledger as `adjustment` with reason "Kitchen waste: …", so Stock → Shrink, the books (5200 Stock losses) and `erp:stock-check` all agree. A dish expands into its recipe.
  - It is valued at average cost. Above `restaurant.waste_approval_minor` (GHS 50 default), a holder of `restaurant.void` approves with their PIN.
  - Kitchen staff have no till access. On the kitchen screen, they identify with username and PIN for each entry; a wrong PIN counts towards the lockout.
  - New permission `restaurant.waste` (level 1).
- **Deferred:**
  - real Bolt Food, Glovo and Chowdeck credentials (only with the owner's keys);
  - booking deposits taken online;
  - a booking widget on the online shop;
  - automatic commission split on delivery payouts.

## Phase 10 — Industry modules wave 2 (owner answers 2026-10-19)
- **Order of delivery,** one PR each: Electronics, Fashion, Services & Packages, Distribution, Manufacturing.
- **Manufacturing:** actual costing (materials issued plus work-centre rates).
- **Services:** both prepaid packs and recurring invoiced memberships.
- **Distribution:** a phone app with each van as a warehouse.
- **Defaults stated to the owner:**
  - Fashion: a size × colour matrix of products under a style.
  - Electronics: serials scanned at receipt and sale; 12-month warranty by default, per product.

## Phase 10a — Electronics (2026-10-20)
- **Serials live in the stock ledger.** Ledger entries for `track_serials` products carry `serials`; `SerialRegistry` keeps `serial_numbers` in the same transaction, and `stock_movement_serials` links each unit to the movements that moved it (its history).
  - A unit's id is a UUID v5 of company + product + number, so nodes agree. Changes ride along with the movement batch in sync, and the newer version wins.
  - Numbers are normalised: upper case, without spaces or dashes.
  - Serials cannot be combined with batches, and need stock tracking. Units are whole numbers.
- **Where numbers are required:** receipts, opening stock, adjustments, transfers, till sales, returns and trade-ins. These are refused online without them.
  - Elsewhere (counts, B2B deliveries, online orders, offline sales typed without a number) the stock moves anyway and the gap is reported. Offline sales add review `serial_problem`.
  - `SerialRegistry::check()` compares numbered units in stock with the quantity on hand, per warehouse. It is shown as a count on the Serial numbers page and is kept out of `StockLedger::check()`, since gaps are legitimate until they are registered.
- **Warranty** = product `warranty_months` from the sale date, stored on the unit when it is sold. A repair job opened with a number we sold inherits "in warranty".
- **Repairs reuse the till.** Parts and labour are priced from the default price list and paid as ordinary sale lines tagged `repair_job_id`, so the stock, tax and the books need no special handling.
  - Warranty or no-charge jobs are handed over: parts are written off by stock adjustment "Warranty repair JOB-…".
  - Status moves along a fixed graph and is logged in the job. A job can go to the supplier (RMA) and come back.
  - Jobs sync up in the `sales` group, with their lines as children.
- **Trade-ins** are a tender (`trade_in`) plus a stock receipt of type `trade_in` at the value given, recorded in `trade_ins`.
  - Both post to 1180 Trade-ins awaiting stock (clearing), which nets to zero. Inventory rises by the value, and the sale counts as fully paid.
  - Acceptance needs `electronics.trade_in` or a supervisor's PIN. The trade-in cannot exceed what is due.
- **Deferred:**
  - subscriptions and licence keys (with Services & Packages, 10c);
  - a serial picker for B2B deliveries;
  - bin-level serial locations;
  - warranty claims costed against suppliers.

## Phase 10b — Fashion & Boutique (2026-10-21)
- **A style is a parent; each size × colour is an ordinary product** (`products.style_id`, `size`, `colour`).
  - The till, stock ledger, promotions, price lists, barcodes and reports work unchanged; the style only groups the variants for the matrix views and the till picker.
  - SKU `CODE-COLOUR-SIZE`, plus an internal EAN-13 starting with prefix 20 (in-store use).
  - Generation is idempotent and keeps shared fields in line. Sizes or colours that already have products cannot be dropped, because products are never deleted.
  - Styles are head-office master data (`catalog` group).
- **Prices:** the style price seeds new variants on the default list. "One price for all" replaces only each variant's default-list, base-unit, undated row, leaving other lists and tiers alone.
- **Consignment = product attribute + share,** owned by a supplier.
  - Stock comes in by adjustment at cost 0, so cost of goods sold is 0 and the consignor's share becomes the cost when settled.
  - Each sale or return line of such a product writes `consignment_sales` (share = net × bp, rounded half up; returns negative) in the sale's transaction.
  - Settlement raises one supplier bill per consignor and branch (expense line to cost of sales) through `BillService`, so approval, payment and the books follow Payables. The rows are marked with the bill, so nothing is settled twice.
- **Alterations** are paid like markdown stickers: a line of the configured non-stock alteration product at the job's agreed charge, kept out of promotions (`alteration:<id>`), validated online (open, same branch, the alteration product).
  - Free jobs are handed over without a sale.
  - Jobs are documents (ALT numbers) in the `sales` group.
- **Sell-through** = sold ÷ (sold + on hand) for a period, by style and season, from sale lines and stock levels (no stored aggregates).
- **Deferred:**
  - a lookbook on the online shop (styles with size/colour selectors);
  - consignment stock statements as PDFs;
  - garment measurements on customers.
