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

