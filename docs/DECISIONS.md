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

## Phase 10c — Services & Packages (2026-10-22)
- **Owner answer (2026-10-19):** both prepaid packs and recurring invoiced memberships. Defaults chosen without further questions (recorded here):
- **A service is an ordinary product with a duration** (`service_minutes`, no stock). Appointments are documents (APT numbers) with a staff member (`employees.takes_appointments`) and a time; overlapping open appointments for one person are refused under a row lock on the employee. Online booking from the web shop is deferred.
- **Prepaid sessions are passes** (`service_passes` with items `{label, product_ids, sessions|null}`; append-only `service_pass_uses`, +1 used / −1 given back).
  - A pack product (`products.pack`) sold to a customer issues one pass per unit; a membership issues one pass per billing period (valid for that period, no carry-over).
  - Using a session is a till line at price 0 with engine id `pass:<id>` (outside promotions); the remaining count is re-checked under a lock in the sale transaction.
  - **Revenue at sale** (packs) and at invoice (memberships); sessions used are free lines. Deferring pack revenue (liability until used) was not chosen, to keep the books simple for small businesses; it can be added later. The pass's value per session (net ÷ sessions) is kept as the commission base.
  - A pack with sessions used cannot be returned at the till; a manager voids the pass and refunds what is fair.
- **Memberships are invoiced, not sold at the till:** one invoice per period on its first day (Receivables), through the shared `InvoiceService`; billing runs at head office only (nightly, catching up missed periods; at most 24 per run per membership). Pause stops billing; resume bills from today; cancel runs to the end of the invoiced period.
- **Commission = line net × rate** (product rate beats the person's), recorded per line in `commissions` in the sale's transaction, negative on returns (the return line keeps the original line's staff member). Payroll adds the month's commission as a taxable earning; employees without a basic salary are included in months they earned commission. Commission is booked with salaries (no separate account).
- **Licence keys** (deferred from 10a): per product and branch, handed out FIFO with `SKIP LOCKED`; online a product out of keys stops the sale, offline it becomes a review item with a back-office "hand out keys still owed". Keys are stored as text (not encrypted with the node's APP_KEY, which other nodes do not share), hidden from serialisation, shown only to `services.licence_keys` and on the receipt; uniqueness by SHA-256 fingerprint.
- **SMS for appointments** reuse the restaurant relay (outbox consumer `sms`); the Services gateway setting falls back to the restaurant one.
- **Deferred:** online booking, deposits on appointments, deferred revenue for packs, resource booking (rooms, chairs), recurring appointments.

## Installation guide and till hosting (2026-10-22)
- **Owner request:** a complete PDF installation manual for every scenario, kept up to date as the phases progress, written for a lay person with the reason for each step.
  - Source: Markdown chapters in `docs/manual/NN-*.md`; built into `docs/manual/Synclytics-ERP-POS-Installation-Guide.pdf` by `pos/scripts/manual.mjs` (marked + Playwright's Chromium; no new system tools). Screenshots from the local dev server by `pos/scripts/manual-screens.mjs` (dev credentials read from `.local-secrets`, never printed; the installation code is masked).
  - **Rule:** every phase updates the affected chapters and rebuilds the PDF (CLAUDE.md end-of-task list).
  - It documents what works **today** (manual installs on Windows and Ubuntu); Phase 13 installers will replace the manual steps and the guide will change with them.
- **The server serves the till at `/pos/`** (Vite mode `server`, base `/pos/`, output `server/public/pos`, git-ignored; `release:build --with-pos` builds into the release). Same origin as the API, so no proxy or second web server; the service worker is scoped to `/pos/`.
- **Windows hosting today** is PHP's built-in server started by Task Scheduler (one request at a time — fine for about 1–4 devices); Ubuntu (Nginx + PHP-FPM) is the recommended production host. **LAN tills without HTTPS** are made offline-capable with the browser's "treat insecure origin as secure" flag for the server address; internet-facing servers must use HTTPS (Let's Encrypt).

## Phase 10d — Data import & export, stock adjustments (2026-10-23)
- **Owner request (2026-10-08):** CSV and PDF export of everything viewable; stock adjustments with additions and subtractions; product and related data by CSV upload; the stock standing before a stock take as CSV/PDF, and stock updated from a CSV. Distribution moves to 10e and Manufacturing to 10f.
- **Export = what is on screen.** One header action group added to every Filament table (`Table::configureUsing` + `pushHeaderActions`; tables that set header actions now push them) exports the visible columns with the current search, filters and sort, all pages, using the displayed values. Report pages (custom Blade) export by rendering the page in its Livewire context and reading its HTML tables — no second copy of each report's logic. CSV: UTF-8 BOM, RFC 4180, formula-injection guard; PDF: dompdf (pure PHP, remote fetching off, DejaVu Sans for ₵), 5,000 rows per table. Exports are audited and blocked when the licence forbids output.
- **Imports run through the domain services** (CatalogService, CustomerService, SupplierService, InventoryService, StockAdjustmentService, StockCountService), so permissions, validation, audit and sync are the same as on screen. **Check** = the same run inside a transaction that is rolled back, so it finds exactly what an import would refuse. Each row is a savepoint; default all-or-nothing, option to skip bad rows. Empty cells mean "unchanged". Results and errors are kept in `data_imports` (with the uploaded file in private storage).
- **Stock adjustment documents** complement (not replace) the single-product "Opening stock / adjustment": header reason with per-line override, modes add / remove / set (set is computed against stock on hand when posting, under a lock). Control: value at average cost above `stock.adjustment_approval_minor` needs `stock.adjust.approve` and a different person from the submitter; smaller ones can be posted by whoever may adjust stock. Posted adjustments are immutable; they sync in the `warehouse` group with their lines.
- **Two stock-take routes:** stock counts (frozen expectations, blind, approval) with count sheets and CSV import of counted quantities (empty = not counted); and the stock-sheet round trip into a set-to adjustment (unchanged rows skipped) for quick corrections.
- **Deferred:** Excel (.xlsx) files (CSV covers Excel and Sheets without a new dependency); scheduled exports by e-mail; importing employees, chart-of-accounts and opening journal lines; PDF versions of the existing print documents (browser "Save as PDF" remains).

## Phase 10e — Sales & Distribution (2026-10-24)
- **Owner answers (2026-10-23):** fully offline phone app; credit within the customer's limit; van loading plus a daily settlement; all four extras — routes and visit plans, rep targets and commission, orders for delivery, returns and empties.
- **A van is a warehouse with its own till** (`warehouses.is_van`, `vehicle_reg`, `rep_user_id`, `home_warehouse_id`). The rep's phone is an ordinary POS device on that till, so offline selling, receipts, credit checks, returns, shifts and cash counts are the till's — nothing new to keep consistent. Loading/unloading are stock transfers dispatched and received at once.
- **Settlement:** snapshot of the van's ledger quantities at start; counts and unload quantities per product; submit, then `distribution.settle` by someone else posts the differences as `count` movements on the van and unloads by transfer. Cash is not re-counted: it comes from the van till's closed shift(s) for the day.
- **Visits and orders** are idempotent records keyed by the phone's id, sent directly or through the offline queue (new sync event types). Orders become customer orders at the van's home warehouse and go through the normal confirm (credit hold over the limit).
- **Commission:** on a van till, lines without a staff member earn the cashier's employee commission (not on containers); reported against monthly targets.
- **Containers:** a container product is sold at its deposit and bought back at the same price (a minus-quantity line, stock comes back). Deposits are booked as sales of containers rather than a deposit liability — simpler for small distributors; revisit with the accountant if needed. Deposit lines are added by the till from the product's container and containers-per-unit, rounded up to whole containers.
- **Released modules** now switch on where branches had reserved them (one migration covering all industry modules released so far).
- **Deferred:** route optimisation and maps, van-to-van transfers, proof of delivery with signatures, offline returns with receipts.

## Container deposits — both options (2026-10-26)
- **Owner:** keep both. Setting `distribution.deposits_as_liability` (default off = sales, as in 10e). On: container lines' net goes to 2340 *Container deposits held* instead of till sales, and container stock sold/returned moves against 1330 *Returnable containers with customers* instead of cost of sales. Read at posting time, so it should be switched at the start of a month.

## Phase 10f — Manufacturing (2026-10-26)
- **Owner answer (2026-10-19):** actual cost. Other choices made without a further round of questions (industry defaults, revisit on request):
- **BOMs** are versioned by revision; one in use per product; in use = immutable. Routing is stored on the BOM (JSON operations) so a BOM syncs as one snapshot with its lines.
- **Work orders copy the BOM at release** (materials per unit incl. expected loss; operations with the work centre's rate at that time), so later BOM or rate changes do not touch running orders.
- **Actual costing via WIP:** materials (ledger value at average cost), labour (minutes × rate) and by-products (at their average cost, credited) collect in 1340 WIP; good output is valued at WIP ÷ good units still expected, so the planned quantity absorbs the whole cost; scrap is a normal loss carried by good units; what remains at finish (late time, unreturned materials, rounding) goes to 5600. No revaluation of stock already sold.
- **Backflush** per material and per operation (default on); production issues are strict (never negative stock). Returns go back at the order's own issue cost so WIP clears exactly.
- **Quality checks** hold good output (no stock movement) until a `manufacturing.qc` holder passes (stock) or fails (scrap, reason required).
- **Labour** is charged at work-centre rates to 5500 (a credit offsetting wages already expensed) — no link to payroll hours.
- **MRP** is a suggestion screen (no auto-ordering), per warehouse, using low-level codes; buy suggestions are exported rather than turned into purchase orders (purchase orders need a supplier choice).
- **Deferred:** serial-numbered components/outputs, standard costing, finite scheduling, subcontracting, shop-floor tablet screen, PO creation from MRP.

## Only plain values in the cache (2026-10-15)
- Laravel 13's `cache.serializable_classes = false` turns cached objects into `__PHP_Incomplete_Class` on file, database and Redis stores. Store scalars and arrays only (heartbeats are Unix timestamps). Tests that touch the cache use the file store, since the array store never serializes.

## HTTP/3 on Ubuntu servers (2026-10-15)
- **443/udp open** so Caddy's HTTP/3 (on by default) is used. Ghana to Contabo EU measured 150–230 ms with some loss; QUIC saves handshake round trips and avoids TCP head-of-line stalls. Contabo has no African region; a server nearer the shops is the next lever if needed.

## No double posting (2026-10-19)
- Three layers: the button that started a request cannot be clicked until it finishes (Livewire's `data-loading`, one CSS rule for the whole back office); forms that stay on the page clear after posting (imports, timesheets, bank reconciliation; Filament create pages already redirect and modals close); and imports keep the file's SHA-256 so the same file into the same type (and the same stock count or adjustment) is refused unless *Import it again* is ticked. Till sales were already idempotent (sale id and device sequence).

## Moving and deleting catalogue lists (2026-10-19)
- Moving products saves each product like an edit (`CatalogService::moveProducts`): audited, synced, nothing else changes. Merging duplicates = move all, then delete the empty one.
- Delete only when unused (`deleteSimple`): products, sub-categories, styles and promotions for categories; products and styles for brands; product units for units. Foreign keys stay `restrict`, so anything missed is refused too. A `*.deleted` outbox event lets branch servers delete it as well, unless something there still uses it (`Replication::DELETABLE`).
## Editing keeps one record; adding from where you are (2026-10-19)
- Every edit screen's uniqueness checks exclude the record itself (checked: SKU, codes, phone, coupons, bins…). The product barcode check now also allows a barcode to move between units of the *same* product; a changed base unit renames the base `product_uoms` row instead of adding a second one.
- Inline creation (`App\Filament\Support\QuickCreate`) calls the same domain services as each record's own screen, so permissions, validation and the audit log are identical; the "+" only shows to people allowed to create that kind of record. Tax groups are not created inline (they need their rates).
## Correcting a base unit (2026-10-19)
- After stock has moved the base unit may be **renamed, never converted**: the base `product_uoms` row keeps its id (prices, barcodes and stock follow) and only its unit changes; documents keep the unit code they printed. Refused when the product already has that unit as a pack size, or when stock is in fractions the new unit cannot hold. Converting (1 pack = 10 pieces) stays a new unit under *Other units*.
## Till details, calculator and waybills (2026-10-19)
- **Owner answers:** calculator on the till (F1, *Use as amount* on payment), a change breakdown, and in the back office; sale comment, walk-in name/phone, industry extras and a per-branch "ask before payment" setting; waybills for deliveries, transfers, returns to suppliers and goods from suppliers.
- **Walk-ins stay walk-ins:** their name and phone sit on the sale (`walk_in_name`, `walk_in_phone`, normalised to +233); *Save as customer* creates a customer without changing the posted sale. Industry details are a whitelisted `extras` JSON (age, sex, weight, pregnancy, guests, next visit), validated on the server.
- **The calculator never uses eval:** a small parser (till and back office alike); percent works like a shop calculator (`200 + 10%` = 220).
- **One waybill document for all four flows** (`waybills`, `WB`), with the goods copied from the source as sent. Proof of delivery by the office (signed copy / photo) or by the receiver through a random 40-character link in the QR code (no sign-in, throttled, valid only while the goods are on the way, cleared once used). Permissions follow what is carried: deliveries `sales.deliver`, transfers `stock.transfer`, returns `stock.expiry`, inbound `stock.receive`.
## Top search covers the whole back office (2026-10-19)
- Every main list declares its searchable columns and result details (`App\Filament\Support\GlobalSearch` trait); Filament only offers results from lists the person may open. A result opens the record's page, else its list searched for it, so lists without a page are still reachable.
- Customer phone search normalises 024… to +233… like the stored numbers.
## Licence generator (2026-10-18)
- **Owner answers:** a vendor panel in this codebase (`/vendor`), only on the licence server; the signing key lives there (password + authenticator to use it); plans + add-ons with per-licence overrides; billing and payments, renewal reminders, a usage and health dashboard from the check-ins, and a licence certificate.
- **Left out of client builds, not just switched off:** `release:build` deletes the panel, its screens, prints and routes; the panel provider is registered only when `LICENSE_SERVER=true` and the class exists. The licensing domain (`app/Domain/Licensing/Vendor`) stays because installations share the code that verifies keys.
- **A licence is never edited:** renew and change package issue a new signed key for the same installation (`replaces` / `superseded_by`), delivered at the next check-in as before; status (active / expiring / grace / expired / revoked / replaced) is worked out from the dates, never stored.
- **Access:** owners (level 6) of the licence server, plus e-mails in `LICENSE_VENDOR_STAFF`; no new permission in the client catalogue. Sudo-style password confirmation on issue, renew and revoke. Every action is in the audit log.
- **Payment details** for invoices come from `LICENSE_PAYMENT_DETAILS`, not the repository.
- `vendor_licenses.customer` (the name signed into the key) predates the customer table, so the relation is `client()`.

## Pharmacy sale rules are a choice (2026-10-18)
- **Owner answers:** four levels (approve / details / patient / free) per kind (POM, controlled); company default with branch overrides; after-sale check as a choice (sign-off, daily summary, nothing); quantity cap; major warnings need a pharmacist; controlled can be locked strict; report of unapproved sales. Default stays the strict rule, so nothing changes until someone chooses otherwise.
- **The strictest medicine in the sale decides**, computed the same way on the till (`pharmacy.ts saleLevel`) and the server (`PharmacyRules::saleLevel`); the server stays the judge (a till with old rules is caught by `pharmacist_needed`). A till without rules from the server assumes strict.
- **Branch overrides are generic:** `branches.settings` (jsonb) holds values only for settings marked `'branch' => true`; `Settings::forBranch` falls back to the company. Values are validated with the same `Settings::valid` as company settings.
- **"Unapproved" is derived, not stored:** a posted sale with a POM/controlled line whose prescription has no pharmacist. Sign-offs are their own rows (`pharmacy_signoffs`, one per sale), so the report needs no extra flag on sales.

## Medicine schedule in bulk (2026-10-18)
- `drug_schedule` in the products file accepts the code or common words (POM, prescription, Rx, CD, controlled, OTC, none) so a pharmacist's own spreadsheet works; anything else is refused rather than guessed.
- Giving a schedule switches on batches and expiry (required for medicines) instead of refusing the row.

## Minimum stock for every product (2026-10-18)
- **Owner answers:** default 5 of the **buying unit**, maximum twice the minimum, own figures per product and store, smart levels "suggest, I accept" by default with "update automatically" as an option, slow movers excluded as an option, low-stock alerts, min/max in the products file, low badge on the till.
- **The default is worked out, not stored** (`ReorderLevels::forWarehouse`): no 2,000 × stores rows to create, keep in step with new products, or rewrite when the setting changes. Only own figures and smart levels are rows (`reorder_rules.source` own | smart | default); "back to the default" marks the row instead of deleting it, so it replicates like any change.
- **Buying unit** = the preferred (else cheapest) active supplier's unit, else the biggest purchasable unit, else the base unit.
- **At the minimum counts as low** (was strictly below), so "minimum 5" reorders when 5 are left.
- **Smart levels reuse the demand forecast** (weekday, payday, holidays, lead time, 95 % safety stock). Only changes of 20 % or more are suggested, so the list stays short; suggestions are a working table rebuilt each Monday. Automatic mode never overrides an own figure; accepting a suggestion does (the person chose it).
- **Slow mover** = older than 180 days and no sale in that store in 180 days; it only drops the default, never an own figure.

## Expired stock: disposals and returns to suppliers (2026-10-18)
- **Owner answers:** settlement by credit note, replacement, refund or nothing; every disposal and return approved by a second person (not only above a value); near expiry gets an alert and dashboard list, a "stop selling N days before" setting, the supplier's return window and markdowns; disposal evidence is a setting (reason / method + witness (default) / + certificate). [+] controlled drugs always need a witness.
- **A disposal is a stock adjustment of kind `disposal`**, not a new document: it reuses the adjustment's approval, voucher, ledger and posting (`stock_adjustment` counter account). `StockAdjustmentService::post` asks `stock.adjust.approve` and a different person for it whatever the value; preparing needs only `stock.expiry` (L2, new), so a pharmacist or storekeeper prepares and a manager approves.
- **A return to a supplier is its own document** (`supplier_returns`, `RTS`), because it has a counterparty and a settlement. Movement type `supplier_return` (strict, at average cost) into a new asset account `1350 Goods returned to suppliers (awaiting credit)`; AutoPoster kind `supplier_returns` clears it to payables (credit note, replacement — with a supplier-ledger debit note when sent) or to stock losses (nothing); kind `supplier_refunds` clears it when the refund is recorded, the shortfall to stock losses. Booking stays with AutoPoster so it posts once, at head office, like every other source.
- **Whole batches only:** the screen ticks batches and takes their whole quantity; partial quantities go through a normal adjustment. Batches of one store per document.
- **Supplier of a batch** is the latest goods receipt line with that batch number; the return window (`suppliers.return_days_before_expiry`) is days before expiry.
- **Stop selling before expiry** works in `StockBatches::fefo` for sales and deliveries: batches inside the window count as expired; offline sales still never refuse.
- **The one-person "Write off" button on Batches & expiry is gone**; that page links to Expiry & returns.

## Clearing test trading before go-live (2026-10-17)
- **A guarded exception to append-only, before go-live only** (`TestTradingReset`, `erp:clear-test-trading`): refused when the go-live plan is `live` or branch servers are paired; dry run unless `--apply` with the exact company name; a backup first. The user triggers on the trading tables (append-only guards) are switched off for the one transaction (`SET CONSTRAINTS ALL IMMEDIATE` first, so waiting balance checks run) and on again; the app's database user owns its tables, so this needs no superuser.
- **Scope, chosen by the owner:** all trading (`TestTradingReset::TABLES`), deleted children first from the foreign-key graph; optional links between trading rows are cleared first (transfers and requests point at each other); rows of kept tables pointing at trading rows are set to null, and a required one stops the clean-up. Trading document numbers restart; customer, staff, asset and SKU numbers do not.
- **The audit log is never touched:** it keeps the test period, and `company.test_trading_cleared` records the counts.

## Opening balances per batch (2026-10-17)
- An opening balance is allowed once per product and warehouse, and for a batch-tracked product once per batch: earlier opening movements of other batches do not count as history; any other movement type, or the same batch, does.

## Creating and updating products are separate imports (2026-10-17)
- **`products` creates, `products_update` updates** (`ProductsUpdateImporter` extends `ProductsImporter`, mode create/update). Create refuses an existing SKU and, unless "allow same name", an existing name in the same industry (spaces and case ignored). Update requires a known SKU and never creates, so re-importing is idempotent.
- **Duplicate clean-up is a command, not a button:** `erp:merge-duplicate-products` (dry run unless `--apply`). Copies are matched by name, industry and base unit, and by import run (from the `product.created` audit rows with reason "CSV import" and `data_imports.completed_at`), so same-name products within one file are kept. Later copies give their barcodes to the kept product (same unit); unused copies are deleted (with a `product.deleted` audit row), used ones switched off; with branch servers paired, copies are only switched off so every node keeps the same products.

## Stock sheet by batch; product location (2026-10-17)
- **"Set" for a batch is turned into an increase or decrease** of the difference with the batch's holding at import time (the adjustment service keeps refusing "set" on batch products, because a batch figure frozen until posting would be wrong). The line note records the count; stock moving between import and posting is the usual risk of a quick correction (a stock count is the tool for that).
- **Expiry corrections apply at import** (`InventoryService::correctBatchExpiry`, audited `stock_batch.expiry_corrected`): the expiry is batch master data, not a stock movement.
- **`products.location`** is free text for shops without bins; bins stay the warehouse feature (counts per bin, put-away), shown on the sheet for reference only.

## Closing and correcting shifts from the back office (2026-10-16)
- **A shift belongs to the device that opened it**; a till re-enrolled after a reinstall is a new device and cannot close the old one, so `ShiftService::closeFromOffice` closes it like a till would (expected, count, over/short; posted by AutoPoster as usual). Vault shifts are refused: the cash chain decides those.
- **Counts are corrected, never edited:** `shift_count_corrections` keeps before/after, reason and person; each posts the difference once (`gl_postings` source `shift_correction`) between `cash_till` and `cash_over_short`. Lists show the latest corrected figure with the original on hover.
- **Permission `cash.setup`** at the shift's branch (Branch Manager and above), not the teller's own.

## Names then and now; receipt copies (2026-10-16)
- **Snapshots stay; renames are a hint and a history, not longer names** (owner: an account renamed five times must not make every record long). `asPerson()` (TextColumn/TextEntry) on the 31 person columns that keep the account id adds an icon and a tooltip with today's name (`People::renamedTo`); prints show the snapshot only. `People::history` reads the `user.updated` audit rows (name, username) for the user's **Name history**.
- **Receipt copies are marked and audited:** a duplicate receipt is a classic refund-fraud tool, so the copy says COPY with the reprinting person and time, and `sale.receipt_reprinted` is logged on each view.
- **Till: direct print is the default button**; a browser cannot print without its dialog, so direct printing needs the desktop app (or Chrome `--kiosk-printing`).

## Named computers (2026-10-16)
- **A lasting browser ID instead of MAC/hostname** (impossible from a browser): `TrackComputer` (persistent panel middleware) sets an encrypted, HttpOnly `ses_computer` cookie (5 years) and keeps `computers` (per tenant, at most one write per computer per 5 minutes, telemetry like `devices.last_seen_at`). Naming is audited (`computer.named`). The key goes into `audit_events.client`, so "which computer" survives renaming.
- **Desktop app in the back office:** a Tauri initialization script sets an unencrypted `ses_client` cookie with name/MAC/LAN address (excluded from cookie encryption), since Livewire requests cannot carry the till's header.
- **Location is a link, not a lookup:** the system never sends addresses to a third party; the administrator opens ipinfo.io for one address when needed.
- **Migration order matters:** the `client` column (`2026_11_06_100003`) runs before migrations that write audit rows (`2026_11_07_100001` pharmacy units), or an update straight to this release would stop.

## Which computer did what; pharmacy roles (2026-10-16)
- **Trust only a proxy on this machine** (`trustProxies(at: 127.0.0.1, ::1)`): Caddy forwards the visitor's address; a request straight from elsewhere cannot fake it.
- **`audit_events.client` (jsonb, not hashed, like `ip`)** from `ClientInfo`: browser and system from the User-Agent; computer name, MAC and LAN address only from the desktop till's `X-SES-Client` header (Tauri `client_info`). Validated and shown as "what the computer reports", not proof. Browsers cannot reveal MAC or hostname, so none is attempted.
- **Back-office sign-ins** (Laravel `Login`, `Logout`, `Failed` on the web guard) are audit rows `auth.*`; tills already had `pos.login`. Devices keep `last_ip` and `last_client`.
- **Clinic roles are not dispensers:** only pharmacist roles hold `pharmacy.dispense` (Ghana: dispensing of POM by a pharmacist). Doctors, PAs, nurses, midwives and lab technicians get `pharmacy.patients`.

## SKUs without the "SKU" prefix (2026-10-15)
- **Shared products: plain numbers from `CatalogService::SHARED_SKU_FROM` + 1 (100001)**, series `SHARED` in `document_sequences`. Six digits without a leading zero, so Excel keeps them intact. Industry products keep `<CODE>-000123`.
- **System SKUs follow a move** (`CatalogService::systemSku`): moving to another industry re-numbers a system-given SKU in the new series. This relaxes "the SKU never changes" for system codes only; receipts keep the SKU they printed (sale lines store it), stock and prices are by product id. Typed codes from an old system never change.
- **Backfill as a migration** (`2026_11_08_100001`): `SKU-000123` → `100123` when shared and free, else the next number of the product's series; the old `SKU` sequence becomes `SHARED`. Through `Recorder` (audit + outbox) so branch servers follow.

## Import industry, other AI services, receipt credit (2026-10-15)
- **Industry imports ask, never guess:** `Importer::industryScoped()` (products). The page shows *For which industry?* (defaulting to the industry chosen at the top, else empty) and refuses Check/Import without it; the domain refuses new products without a choice once licensed industries exist (calls from code pass `industry`). An `industry` column (name, code, module key or a name prefix; "shared") overrides per row and is the only way an import moves an existing product. `CatalogService::moveToIndustry` + a products bulk action repair earlier imports; SKUs never change.
- **Other AI services through one OpenAI-compatible provider** (`OpenAiCompatibleProvider`, presets in `config/ai.php`): Gemini (OpenAI endpoint), Groq, OpenRouter, Mistral, OpenAI, or a custom `/v1` address. Key in `.env` like Claude's. Cost logged as 0 (free plans, unknown prices), so the budget gate stays Claude's; masking follows `isCloud()`. PDFs are read by Claude only.
- **Receipt credit** (`Branding::receiptCredit`): two short lines (fit 58 mm) from `config('brand.*')`, so a reseller's own vendor and phone show instead; empty with white label.

## Back-office clarity styles (2026-10-15)
- **A static stylesheet** (`public/css/seslabs/admin.css`, linked by a HEAD_END render hook with a file-time version) instead of a compiled Filament theme: no build step on servers, and it rides on Filament's own classes (`fi-active`) and colour variables (`--primary-*`), so the brand colour follows Company → Branding.
- **Covers** the active sidebar item, its group heading, active tabs and every native file input (`::file-selector-button`).

## Imports in batches (2026-10-15)
- **Why:** `AuditLog::record` locks the tenant's chain head (`lockForUpdate`) until commit, so one transaction around a whole import serialised every other write in the company (sales from tills, settings, the audit line every export writes) for the import's length. Requests then waited past Caddy's 300 s; PHP's `max_execution_time` does not count time spent waiting, so nothing stopped them.
- **Now:** `ImportService::BATCH` (50) rows per transaction (a few seconds of lock). Check: each batch undone. Import, all or nothing: a full check pass first, then the import pass; rows that fail only in the second pass (data changed in between) are reported, not imported. With *skip rows with errors* a single pass.
- **One transaction stays** for importers whose rows build one document (`inBatches() = false`: opening balances, stock adjustments).

## Stock left on the till (2026-10-15)
- **Setting `pos.show_stock`, off by default**, sent in the till's bootstrap. Off now also hides the search list's "in stock" figure, so the choice is the shop's.
- **Worked out on the till** (`stock.ts`): the catalog's `on_hand` for the till's warehouse, less the cart and queued (unsent or refused) sales, in base units through each unit's factor. Sent sales are already in the server's figure, which the till re-reads on every `stock.changed` event; offline it counts only this till's sales. No new API or polling.

## Industry units (2026-10-15)
- **`IndustryUnits::UNITS` per module**, added by `ModuleService::setStatus` when the module is enabled and once by migration `2026_11_07_100001` for tenants already running it. Starter data like `TenantBaseline`, but added to a non-empty list: only codes and names the tenant does not have, so renamed or deleted-and-recreated units are respected. Written through `Recorder` as the system actor (audit + outbox, reaches branch servers).
- **Imports match units by code or name** (`Importer::unitId`): code, name, singular of a plural, then a name starting with what was typed (3+ letters, `Cont.` → Container).

## Imports: time limit and barcodes through Excel (2026-10-15)
- **Imports run up to 30 minutes per request** (`set_time_limit(1800)`, as the go-live and backup pages). Measured about 40 ms a row; PHP-FPM's 30 s stopped lists over roughly 700 rows. Caddy's 300 s read timeout still caps one request (some 7,000 rows); a queued import is the next step if larger files are needed.
- **Excel is the default download** for templates and current data: `XlsxWriter` writes digit strings of 12+ digits, and any with a leading zero, as text.
- **CSV cells** `0…` and 12+ digit numbers are written `="…"` (Excel keeps them as text); `CsvFile::unformula` reads them back everywhere.
- **Restoring zeros only with proof:** a 7- or 11-digit code becomes EAN-8 / UPC-A only when the padded code's GS1 check digit is right. A 12-digit code stays as it is (a valid UPC-A, which is also how scanners send it). Shortened scientific numbers are refused, never guessed.
## Desktop till start-up and build PC recovery (2026-10-15)
- **Start on the local set-up page, not the server.** The page checks `<server>/up` (no-cors, 8 s) and opens the till when it answers. `till.json` gains `verified` (the server answered at least once); a verified server opens even when unreachable, so offline selling is unchanged. A never-verified one stays on the set-up page. *Use this address anyway* saves without the check, for a server not yet switched on.
- **Uninstall keeps settings** (Tauri's default; `%APPDATA%\app.seslabs.till` and `%LOCALAPPDATA%\app.seslabs.till`). Kept on purpose: the WebView folder holds unsent offline sales. The manual says how to clear both.
- **`Cargo.lock` committed** so local and CI builds of the till use the same Rust libraries.
- **Client pack stays out of git** (client names, prices, payment details). Its generator moved from a temporary folder to `client-pack/generator/`, backed up with `.local-secrets/` (two copies). The build-PC guide lists what cannot be downloaded again.

## Only what the business runs is offered (2026-10-14)
- **Same rule as the menus:** `ModuleService::enabledAnywhere`, which follows the industry chosen at the top, decides industry roles (`RoleCatalog::MODULE_ROLES`), settings sections, message templates and device kinds (`Device::kindsFor`, checked again in `DeviceService`). HR roles follow the licence (`FEATURE_ROLES`).
- **Hidden, never deleted:** a role already held stays visible; hidden settings keep their values (the save skips fields that were not shown); templates stay stored and come back with the module.
- **The kiosk kind is withdrawn** until self-checkout is built (feature flags stay off until built). The key remains in `Device::KINDS` for labels only.

## Shared server option (2026-10-14)
- **Separate copies, not several companies in one copy.** The app can hold several companies, but one copy shares one licence (limits are per installation), one backup and restore, one update and one database. One client's restore or fault would then reach the others. Each copy has its own `installation_id`, so licences stay separate even on one machine.
- **Isolation by Unix user:** each copy runs PHP, cron and Reverb as its own system user, with `.env` readable only by that group and private storage closed to others. Code stays world-readable because it is not secret. Caddy reaches each pool through a socket owned by `caddy`, and is not added to the copies' groups, so it cannot read their `.env`.
- **The standard copy is unchanged** (www-data, PHP's default pool, port 8080, database `erp`), so existing servers update as before; only the Caddyfile is split into an importing main file and a site file.
- **Domain required** for a shared copy: each client needs its own name for its certificate and for its tills.
- **Sizing:** about 3 small clients per 12 GB server, with `pm = ondemand` so idle clients cost little memory; the slow-pages list tells when a client should move out.

## Applying the speed suggestions (2026-10-13)
- **"Background workers":** the app has no Laravel queue jobs (store pushes, messages and webhooks run in the `erp:relay` loop; posting and reports on the scheduler), so no worker was missing. The real lag was posting every 5 minutes; it is now every minute. Laravel's queue stays unused.
- **Slow pages are measured where they happen:** a terminable middleware (a singleton, so `terminate()` sees `handle()`'s numbers) counts queries with one `DB::listen` per process and writes after the answer is sent. Paths only (route URI, or the Livewire screen name), never query strings, so no searches or personal data are logged. Threshold `ERP_SLOW_REQUEST_MS`.
- **The OPcache check runs only from the web server:** the CLI has OPcache off on purpose, so `erp:health` skips it.
- **Updates = re-running the installer**, which already did everything except the backup; that is now its first step on an existing installation. No separate update script to maintain.
- **Remaining till lint warnings are deliberate:** data-loading effects (`set-state-in-effect`) and date reads in an event handler or on render.

## Speed and clean-up (2026-10-13)
- **Where the time went:** on a warm page, the database work per back-office page is small (6–15 queries, measured with a probe). The cost was PHP setup (no OPcache on Windows, no Filament component cache) and, as data grows, sequential scans: PostgreSQL does not index foreign keys, and `tenant_id`, used by every global scope, was unindexed on 61 tables.
- **Indexes are found, not listed:** the migration creates an index for every single-column foreign key that no index starts with. `DatabaseIndexesTest` fails on any future one, so new migrations must add `->index()` (or a composite starting with the column).
- **OPcache `validate_timestamps=1, revalidate_freq=60`** rather than 0. Updates are done by hand (Chapter 12.4) without always restarting PHP, so a cache that never checks files would serve old code after an update. One check a minute costs almost nothing.
- **Lazy-loading probe:** a full test run with `preventLazyLoading` logging found only 7 places (fixed). No blanket `Model::shouldBeStrict()`, because single-record lazy loads in actions are harmless and strict mode would break them.
- **Not changed:** per-request memoisation of the industry and module checks (1–2 queries a page, not worth the stale-state risk in tests); response caching of back-office pages (per-user permissions make it unsafe).

## Industry menus, refused saves, system SKUs (2026-10-13)
- **One switch for module screens:** `ModuleService::enabledAnywhere` narrows to the chosen industry's branches. Every module screen and field already asks it, so the menus, product form sections and reports follow the industry switcher together. Pharmacy screens now ask it too.
- **`Halt` stops quietly everywhere:** a `Livewire\on('exception')` hook swallows Filament's `Halt` after `Erp::run` has shown *Not saved*. That covers custom-page methods, which Filament does not wrap, without touching each page.
- **SKUs are generated** (owner: "All SKUs should be auto generated to avoid duplicated"). The format is industry code + six digits, from `document_sequences` (node `catalog`), skipping codes already taken; products are HQ master data, so one sequence is enough.
  - An SKU never changes after creation.
  - Exceptions: imports keep codes from a previous system (their prices and stock rows match on them), fashion variants keep their style-size-colour SKUs, and tests may pass fixed SKUs to the service.

## Separate books per company (2026-10-13)
- **Default = separate books per industry** (owner, 2026-10-13: "make separate books per industry the default option"). Setting `finance.separate_books` is on by default.
  - `IndustryService::ensureCompany` creates the industry's legal entity on first use, named "{company} – {industry}", plus a primary entity if none exists.
  - `Settings::set` calls `applyBooksChoice` when the setting is switched.
  - Running installations got the setting stored as off (a migration, not a seeder), so nobody's books split mid-year without a decision. A single-industry client may prefer *off* to avoid an empty head office company; the first-setup guide says so.
- **The choice per industry is its legal entity.** With none, the industry is a profit centre in one set of books. With one, its branches move to that company: `branches.legal_entity_id` follows the industry, so receipts, E-VAT and books all change together. No separate setting can disagree with it.
- **A journal line's company = its branch's legal entity, else the primary**, stamped at posting like the industry.
  - Lines from before any entity existed count as the primary's (report filters and the backfill).
  - A manual line without a branch may name its company.
- **Intercompany is automatic and pairwise.** `GeneralLedger::post` nets each entity's lines. Unbalanced entities are paired debtor-to-creditor on one control account (`intercompany`, 1250), with `party_type = legal_entity`, so the account cannot be posted by hand. Each pair's two sides cancel in the consolidated view, and `GlCheck` proves both per-entry-per-entity balance and pair agreement.
- **Transfer receipts credit the sender's in-transit** (AutoPoster), which turns a cross-company transfer into intercompany automatically and fixes in-transit drifting between industries.
- **Fiscal periods, numbering and the chart of accounts stay shared** across the group (one database, one accountant). E-VAT sends the selling company's TIN; the GRA API key must be authorised for each TIN, and separate keys per company are left until a client needs them.
- **Changing an industry's company mid-year** is allowed but documented as a start-of-year step; opening balances move by journal.

## Industries, part C: accounts by industry (2026-10-13)
- **Owner answers:**
  - profit centres first (one set of books, one TIN), separate books per industry later as their own phase;
  - head office costs unshared by default, with sharing by sales as an option;
  - stock moved between industries shows in each industry's books at cost.
- **Industry is stamped on the journal line** (`industry_id`, from the branch at posting time) instead of joined through the branch.
  - Posted history never moves when a branch changes industry, and an allocation line can carry an industry without a branch.
  - The one-time backfill lifts the append-only trigger for a single UPDATE that only adds the tag; no amount, account or date changes.
- **Sharing basis = the month's income per industry** in the ledger (not till receipts), so invoices and online sales count too.
  - Largest-remainder rounding makes the shares sum exactly.
  - It is a separate journal kind, `allocation`, tagged `ho_share:YYYY-MM`, so it is excluded from its own basis and posted once a month. It is reversible like a manual journal.
- **Balance sheet by industry is indicative only.** Cash, receivables, payables and in-transit balances are often company-level, so the page says it does not balance on its own.
- **Separate legal entities** (own TIN, VAT return, due-to/due-from) remain open; `industries.legal_entity_id` is already in place for them.

## Industries, part B: stock requests (2026-10-13)
- **A request is the only way across industries.** `TransferService::saveDraft` refuses branches of two different industries unless an approved `StockRequest` is passed. It also refuses any line whose product belongs to a third industry on either side, so separate catalogues hold even through head office. Company-level branches trade freely.
- **Both sides approve, in order:** the asking side first (a holder of `stock.request.approve` at the asking warehouse, not the requester: maker-checker), then the supplier. Rejection is open to the supplier at any stage, and to the asking side's approver at its own stage.
- **Requests use the asker's catalogue**; the supplier maps each line to its own product when sending.
  - Transfer lines carry `to_product_id`. Receipt posts the mapped product, one base unit for one base unit (the item is the same; only the catalogue entry differs), at the sender's average cost.
  - Batches are recreated on the receiving product with the same number and expiry (`StockBatches::ensure`).
  - Serial-numbered items are not mapped. Within an industry they go by a normal transfer that names the serials.
- **At cost only for now.** Separate books (due-to/due-from between industries) come with part C.
- **Sending = creating and dispatching the transfer** in one step, so a request never sits as a forgotten draft; receiving the transfer closes the request.

## Industries, part A (2026-10-12)
- **Owner answers:**
  - one industry per branch; an industry has many branches; head office or a central warehouse may be company-level;
  - fully separate catalogues;
  - books chosen per installation (part C);
  - cross-industry transfers at cost, with both sides approving (part B).
- **Structure:**
  - `industries` sits between the tenant and its branches (`branches.industry_id`, nullable = company-level).
  - It is created implicitly when an industry module is first switched on for a branch (`IndustryService::ensureFor`), so the setup wizard and existing flows need no new step.
  - "Industry module" means `kind: industry` and not `addon` (Distribution is an add-on).
- **Access:** the new scope `industry` sits between legal entity and branch in the scope path, so industry managers are ordinary grants. `branchIds()` expands an industry grant to its branches.
- **The switcher narrows, never widens:**
  - the session choice intersects with what the person may see, inside `Erp::branchIds` (the one function about 40 screens already use);
  - options are only industries the person reaches;
  - an invalid stored choice falls back to all.
- **Catalogue separation by an `industry_id` on products and price lists**, null = shared:
  - existing data stays sold everywhere until moved, so there is no forced data migration;
  - tills receive their branch industry's items plus shared ones;
  - customers and suppliers stay company-wide (a customer can shop in both industries).
- **Only purchased and installed modules show** (owner, 2026-10-12):
  - "purchased" = in the licence (`LicenseManager::allowsModule`); `ModuleService::licensed()` feeds the wizard, the Modules screen and the industry module picker;
  - "installed" = enabled at a branch; `Industry::inUse()` (licensed and installed) feeds the switcher and dashboards, `Industry::licensed()` the branch, product, price-list and access pickers;
  - `ModuleService::enabledAnywhere` also requires the licence, so every module screen gated on it disappears after a downgrade, with no data deleted;
  - a licence without a module list (development, before activation) covers every module.

## Cash control: vaults and the Teller → Cashier → Income Controller chain (2026-10-12)
- **Owner answers:**
  - a vault is a cash tray with its own float, one per till per shift (shifts configurable, default Morning/Afternoon);
  - blind counts at every step;
  - the Cashier does not sell;
  - differences are flagged, explained and approved, and a shortage can be charged to staff;
  - the Income Controller records and reconciles the bank deposit;
  - cash chain first, industries after.
- **Optional per company** (setting `cash.vaults`, off by default): a one-PC shop keeps the simple "count at close". Off, nothing changes; on, a till opens only on an issued vault.
- **Blind recount, not a hidden comparison:** a count that differs from the previous person's is not accepted the first time. The counter is asked to recount without seeing either figure, and the second count stands.
  - The vault's recount is remembered in the row (`cashier_recounts`). The handover's is held by the Income Control page, a deliberate simplification: the controller is the last step.
  - The teller's close never reveals the expected figure on a vault (so cash cannot be adjusted before the Cashier counts it), offline included.
- **Accountability follows the verified count:**
  - the teller's difference is the Cashier's count minus the system's expected cash (the teller's own count is kept for the reconciliation);
  - the Cashier's difference is the Income Controller's count minus what the Cashier declared.
- **Money in the books moves in steps** (`cash_movements`, append-only, one GL entry each):
  - office → till (float) → *Cash with cashiers* → office → bank;
  - differences park in *Cash differences awaiting approval* until decided, so an undecided difference is visible on the balance sheet.
  - A staff charge reuses staff loans (kind `shortage`, paid from the differences account), so payroll recovers it with no new mechanism.
- **Roles:**
  - the old "Cashier" (sells) is renamed **Teller** by migration (key and name), so existing grants and tests keep their meaning;
  - the new **Cashier** and **Income Controller** have no `pos.access` (segregation of duties);
  - branch managers set up vaults but do not handle the chain.

## Granting roles vs the licence (2026-10-12)
- **The grant rule:** "the granter must hold every permission of the role" is checked against the granter's roles, ignoring licence switches (`Authorizer::holds`).
- **Use is separate:** what anyone can actually use stays licence-filtered (`allows`). A role granted while HR is unlicensed gives HR rights only once HR is licensed.
- **Why:** built-in roles carry cross-cutting permissions (e.g. "request leave" in nearly every role). Without this, granting most roles was impossible on any licence without HR.

## Licence signing key (2026-10-12)
- **Owner:** "create the real key now" (rather than a test-only route) when the first test PC needed a licence.
- **Key and storage:**
  - Key `syn-2026-1` (Ed25519); public half committed in `config/license.php`.
  - Private half in the git-ignored `.local-secrets/` on the development PC, with an offline copy (USB in a safe) to be made by the owner.
  - It moves to the licence server when that is set up.
- **Development keys** remain accepted only in non-release builds (`LICENSE_DEV_PUBLIC_KEY`), as before.

## Ubuntu server: Caddy + HTTPS always (2026-10-12)
- **Owner:** "do the same for the Ubuntu installer".
- **Caddy replaces nginx and certbot on Ubuntu too.**
  - One web server and one Caddyfile shape on both platforms, so one guide and one set of CI checks.
  - It gives a local certificate authority for servers without a domain, with short-lived certificates renewed automatically (nginx would have needed our own openssl CA plus a renewal timer), and Let's Encrypt with renewal built in.
  - Installed from Caddy's signed apt repository, not a downloaded binary.
- **PHP stays PHP-FPM over its unix socket.** The `caddy` user joins `www-data` for socket access.
- **Admin API off.** Changes are applied with `systemctl restart caddy`. The packaged `reload` needs the admin endpoint, and leaving that on localhost would let any local account reconfigure the web server.
- **The script switches on `ufw`** (SSH on the port `sshd -T` reports, plus 80 and 443). Before, the script opened nothing, but Reverb listened on 0.0.0.0:8080; it is now loopback-only.
- **Updates retire nginx** only when it served nothing but our site. Otherwise the script stops rather than take ports another site uses.
- **The Docker image keeps nginx inside the container** and expects the host's HTTPS proxy in front; it is unchanged.

## Windows server: Caddy + PHP FastCGI + HTTPS (2026-10-12)
- **Owner:** yes to replacing `php artisan serve` over http before the penetration test, so the firm is not paid to report something we already know.
- **Caddy over IIS or nginx for Windows.**
  - It is one checksum-verified binary with no Windows features to enable.
  - It provides automatic HTTPS: Let's Encrypt for an internet name, and its own local certificate authority on a shop network.
  - nginx's Windows build is not meant for production; IIS needs features installed and its own certificate handling.
- **PHP runs as php-cgi FastCGI workers:**
  - 4 by default, `-Workers`;
  - each on 127.0.0.1:900x, load-balanced least-connections;
  - each in a cmd loop with `PHP_FCGI_MAX_REQUESTS=0`, because Windows php-cgi cannot manage its own children and otherwise exits after 500 requests.
- **HTTPS on the shop network is required, not optional.** Browsers only give the till its signing key, offline PIN check, service worker and install on a secure origin. Until now each device needed a Chrome flag; now each device trusts the server's certificate authority once.
  - Hardening: names on the certificate authority say which server it belongs to, and its key folder is readable by Administrators and SYSTEM only.
  - Uninstall removes it from the trusted list.
  - The certificate covers the IP address and the computer name at install time; a new address means re-running the installer and re-trusting.
- **Minimal attack surface:**
  - Only 80 and 443 are open. Port 80 redirects, and serves only `/ca.crt` on a shop network.
  - Caddy's admin API is off; the `Server` header is removed.
  - Hidden paths give 404.
  - Reverb and PHP listen on loopback.
- **Live updates go through the same origin** (`/app` proxied to Reverb), so no extra port is open. The server publishes to Reverb on loopback over http (`REVERB_PUBLISH_*`), so PHP never has to trust the local certificate authority. The bootstrap hands out only the public client address.
- **Updates migrate in place:** re-running the installer moves an http install to https and retires the old task and firewall rule. Devices then need the one-time trust (4.9).

## Penetration test pack (2026-10-12)
- **Owner answers:**
  - environment: a test machine at the SyncLytics office (not hosted);
  - test type: grey box (test sign-ins, no source code);
  - firms: quotes from Ghana-based and international firms;
  - testers: certified testers required.
- **We prepare, the owner contracts.** The firm must be independent, so we do not test ourselves in its place. We make its paid days count instead:
  - a precise scope;
  - one sign-in per role, because role-to-role and company-to-company access are the highest-value targets in multi-tenant business software;
  - a ready test machine.
- **The test copy is built by a command, not by hand:**
  - It refuses any installation that already has a company, so it can never run where real data is.
  - It is one database transaction: a wrong licence key leaves the installation empty and the command can be re-run.
  - It creates a second company only so that isolation can be attacked.
  - Secrets go to one private file (0600), never the screen, ready for an encrypted hand-over.
- **Severity drives the pilot:** Critical and High findings block it until they are retested, with deadlines of 72 hours and 14 days. Medium needs a dated plan. Every fix starts with a test that performs the attack, as in the rest of the codebase.

## Product name and branding (2026-10-31)
- **Company names (owner, 2026-10-12):** inside the product (licence messages, installer and till Publisher and copyright, guide cover) the publisher stays **SyncLytics** (`BRAND_VENDOR`). Contracts, proposals and invoices use the legal name **SyncLytics Enterprise Systems**, never "SyncLytics Ltd".
- **Product and publisher are separate (2026-10-09 owner clarification):** the owner wanted a product name that is not the company name, not to hide the company. Industry practice (Microsoft Teams / Microsoft Corporation, QuickBooks / Intuit) is the product name on the product and the company as publisher and licensor. So **SES Labs** is on every screen, receipt, icon and "Powered by"; **SyncLytics** is the publisher: licence messages ("Contact SyncLytics to renew"), Windows "Publisher" and copyright for the desktop till and the server installer, and "Published by" on the guide cover. It is one setting (`BRAND_VENDOR`, `brand.json` `company`), so a white-label reseller can replace it. The company's own Synclytics logos are not product artwork and stay out of the repo.
- **Owner answers:** the product must not carry the company name. After a first proposal (Ervanta, from the owner's shortlist) the owner chose **SES Labs**, tagline **"Engineering Solutions. Empowering Businesses."** (2026-10-31). A trademark and domain check is the owner's. Branding: **licence-controlled white-label** — client brand first with "Powered by SES Labs", removable by the licence feature `white_label`. Folder, service and package names use the space-free `SESLabs` / `seslabs` / `ses-labs`.
- **The name is one setting** (`config/brand.php`, `BRAND_NAME`/`BRAND_VENDOR`/`BRAND_WEBSITE`) and one artwork folder (`branding/product`) driving every generated asset (`npm run brand`), so a later rename is cheap. Installers take the name as a parameter (`/DBrand`, `-Brand`, `SLUG`). Protocol and data identifiers are deliberately unbranded or kept: webhook headers became neutral `X-Webhook-*`; the till's IndexedDB name stays `synclytics-till` (renaming strands offline queues), `.synbak`/`SYNBAK1` and `SYN1-`/`SYNREQ-` are formats; old till export files are still accepted; backups are found by their `.synbak` extension, not a name prefix, and pruned newest-first by the date stamp in the name (an alphabetical sort would have deleted the newest backup right after a rename).
- **Client brand assets live in the database** (`brand_assets`, base64, re-encoded to ≤ 1200 px / 512 px icon, ≤ 700 KB) instead of files, so they replicate to branch servers (sync group `org`) and are in every backup with no file sync; removal empties the row (replicates as a change). Served by `/brand/{slug}/{kind}?v=<sha>` (public — they print on receipts; immutable cache; `nosniff`; SVG under a `default-src 'none'; sandbox` policy). SVGs are cleaned on upload (no DOCTYPE/entities, scripts, foreignObject, event handlers, outside or javascript references, @import); rasters are decoded and re-encoded (metadata dropped) with GD.
- **Where the brand shows:** Filament brand logo/dark logo/favicon/primary colour per signed-in company (closures; a light plate behind the normal logo in dark mode when there is no dark logo; monogram + name until there is a logo); the sign-in page shows the client brand only on a single-company server; tills get `brand` in the bootstrap (icon, accent bar, receipt logo — rasterised in the till with Floyd–Steinberg dithering to ≤ 384 dots and sent as ESC/POS `GS v 0`, cached in IndexedDB for offline); prints embed the logo as a data URI (no network), Dompdf exports raster logos only; the online shop uses the logo (light/dark), icon and the colour as its accent only when it keeps 4.5:1 contrast. Brand colour must be `#RRGGBB`; text colour on it is chosen by WCAG contrast.
- **White-label is explicit:** `features: null` (all features) does not remove "Powered by"; only a licence that lists `white_label` does.

## Phase 14 — Pilot and launch (2026-10-30)
- **Owner answers:** migration from Excel/CSV, QuickBooks, another till system, or starting fresh — all four; training: cashier quick card, role guides, practice mode, video scripts — all four; cut-over: all three styles available, parallel run the default; pilot: all three styles available, one shop with support on call the default.
- **Migration reuses the import engine** (check first, all-or-nothing, audit) instead of a separate tool: QuickBooks/other-POS support is a column-alias preset plus row tidying (`ImportSources`), so a new source system is a table entry, not code. Balances go through the sub-ledger services (customer/supplier openings, loyalty "opening" entries, stored values with `*_opening` documents) and are posted against `opening_equity`, which must end at zero — that tie-out is the go-live test. The opening trial balance is one journal (`Importer::finish` hook), loadable once (reverse to reload); control-account lines are skipped rather than refused so a QuickBooks trial balance loads as exported.
- **The go-live plan is data, not settings** (`go_live_plans`, one per company): the checklist is computed from the data each time (it cannot drift), with hand ticks only for what the system cannot see (devices tested, staff trained). Going live needs a backup taken that day; open required steps need a recorded reason. Rolling back records the decision; restoring the go-live backup stays a technician's action (Chapter 12.3), after exporting what happened since.
- **Parallel run:** our day totals are computed from posted sales in the company's time zone; a day is clean within 1.00 or with an explanation; three clean days turn the step green.
- **Practice company = a separate tenant** (`tenants.is_practice`, `practice_of`) on the same server rather than a "training mode" flag on sales: its books, stock and numbering can never mix with the real ones, and every screen works unchanged. Copied: branches/tills, enabled modules, settings (except E-VAT, AI, messaging, channels, licence), products, prices, main-store stock at average cost; customers are made up. Staff logins copy the password hash, PIN hash and two-step secret (same person, same credentials; e-mail `+practice` because e-mail is unique); grants are copied by role key and branch code. One guard each in `FiscalService::mode` (off), `Messenger::deliver` (status `practice`), `WebhookDispatcher` (no deliveries) and `ChannelSync` (no pushes). One active practice company per company; archiving deactivates its logins (ledgers are never deleted).
- **Problem reports need no permission** (they change no business data; IT Admin can report too); reading and closing need the technical `support.view`. Reports are e-mailed directly (not through customer messaging), so a practice company's reports still reach support. Context = page, browser, versions and the server's last error lines from the past day (shortened) — no request bodies.
- **Pilot report:** the scheduler checks every 15 minutes and sends once a day at the plan's time, during the parallel run and the first 60 days live. New permission `golive.manage` (Owner, General Manager); Core 0.14.0; `config('erp.version')` 0.25.0; env `ERP_SUPPORT_EMAIL`.
- **Training material** is Markdown in `docs/training`, built to PDFs like the installation guide; the cashier card is a two-column single sheet. Video scripts rather than videos (the owner records them in the practice company).

## Phase 13 — Hardening (2026-10-29)
- **Owner answers:** backups to all four destinations (USB/second drive, S3-compatible, Google Drive, SFTP); Windows installer, Docker Compose and an Ubuntu script; build the desktop till now; all three E-VAT modes for the developer to choose.
- **Backups are built in, not scripts:** one encrypted file (pg_dump custom format + .env + private files + manifest with row counts) per run; passphrase in `.env` and on paper; destinations are node-local with encrypted credentials; S3 by our own SigV4 signing (verified against AWS's published example) instead of the AWS SDK; SFTP with phpseclib; Google Drive by REST with the client's own OAuth app. The restore drill uses separate `psql` sessions and a scratch database so the live connection is never touched; without CREATEDB it reports "not run" rather than failing.
- **E-VAT** is a pluggable adapter; the GRA payload follows the usual E-VAT structure but must be confirmed in GRA's sandbox (GRA gives the spec to onboarded taxpayers). Realtime tries inline with a short timeout so the receipt can carry the code; otherwise the relay sends it.
- **Installers automate the manual chapters** (same steps, same choices), so the guide stays the reference; Windows keeps `artisan serve` + Task Scheduler (as Chapter 4) rather than a new web server; PostgreSQL from EDB's binaries ZIP registered as a service (no installer UI).
- **Desktop till = a window on the server's till** plus hardware commands; navigation is restricted to the configured server, so the bridge cannot be reached from other sites. ESC/POS is generated in the PWA (tested in Vitest) and sent raw.
- **Security headers** without a full CSP for the back office (Filament/Livewire inline scripts); frame-ancestors/object-src/base-uri/form-action locked.

## Phase 12 — Reporting, notifications, integrations (2026-10-28)
- **Owner answers:** build all four SMS options (Hubtel, Arkesel, mNotify, generic webhook) for the client to choose; any SMTP server for e-mail; WhatsApp Business built but off until keys; public API with scoped keys plus QuickBooks, Xero and Sage exports.
- **Provider credentials** are per server, encrypted with that server's key and never synced or shown; templates (wording) are company data synced from head office. E-mail credentials stay in `.env` (Laravel mail), like other secrets.
- **One message log** for every channel, retried with back-off; "not configured" is logged rather than dropped so the business sees what would have gone out.
- **Customer messages a business might not want sent automatically start off** (invoice e-mail, payment SMS); receipts are only sent on request; booking/appointment messages keep their existing behaviour.
- **Approvals in the bell** go to holders of the approving permission for that branch, never the submitter.
- **Report builder over fixed datasets** (SQL in code, never from users), each behind its permission; branch grants filtered; scheduled reports run as their owner.
- **Excel without a library:** a small writer/reader (zip + XML) instead of a large dependency.
- **API keys act as a person** (service user) on top of scopes, so the API never exceeds back-office access and reuses RBAC; tokens are Sanctum (hashed), shown once; webhooks carry one event id across retries and an HMAC signature with a timestamp.
- **Accounting exports** are journal-line files (one journal per entry) for an accountant using another program; accounts are matched by code.

## Phase 11 — Insights and AI (2026-10-27)
- **Owner answers:** no cloud AI for now; if cloud is used, names masked and personal data local; copilot for managers and up; all four extras.
- **Classical first:** forecasts, stock health, customer, pricing, cash-flow and loss-prevention analytics are plain arithmetic on the node — work offline, cost nothing, need no model. Alerts are node-local (recomputed where they run), not synced.
- **Forecast model:** deliberately simple and explainable (weighted recent level × weekday × payday × holiday), shrunk towards neutral factors, rather than a black box; 12 weeks of history.
- **Loss prevention:** each cashier compared with the others excluding themselves; a flag needs ≥3 events, ≥2× peers and z ≥ 2, so one refund never flags anyone. The score is "look here", not proof — wording on screen says so. Till line voids and no-sale drawer opens are not captured yet.
- **AI governance:** one gateway; provider off/ollama/claude (default off); Claude needs a key in `.env` and a budget > 0 (default 0); every call logged with the masked prompt; the AI reads through fixed read-only report functions (never SQL) with the person's own permissions; it never posts — invoice scans pre-fill a form, descriptions are drafts.
- **Privacy:** masking of names (from tool results), phones and e-mails both ways; with "personal data local" on, a cloud conversation that needs customer/staff details continues on the local model, or shows the tables without AI wording.
- **Local models:** defaults `qwen2.5:7b` (tools) and `qwen2.5vl:7b` (invoice photos); PDFs only with Claude.
- **Company-wide figures** (cash, cash flow, customers, margins, supplier balances) need a company-wide grant; branch grants see their branches' sales and stock only.

## Themes (2026-10-24)
- **Owner request:** System, Dark and Light for the whole app.
- Back office: Filament's built-in switcher (profile menu; per browser). Custom pages use `currentColor` mixes instead of fixed black borders so they read in both.
- Till app (all screens: till, handheld, kitchen): `<html data-theme>` with light tokens on `:root`, dark tokens under `[data-theme=dark]` and `prefers-color-scheme: dark` unless `[data-theme=light]`; choice in `localStorage`, applied before first paint; picker at the bottom left. The leftover Vite starter stylesheet was replaced with a small base.
- Online shop: the same three choices in the footer (per visitor's browser).
- Printed documents, receipts and PDFs stay black on white.
