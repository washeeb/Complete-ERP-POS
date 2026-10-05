# Master Prompt — Complete ERP + POS (Synclytics)

> Paste this whole file to the coding agent as its first message, or keep it in the
> repo root and tell the agent: "Follow `ERP-POS-MASTER-PROMPT.md`."

---

## 0. Operating rules (read first, obey always)

1. **Read everything first before starting.** Read this whole file, then every file
   already in this folder, then the reference folder in §0.1. Do not write code
   until you have done so.
2. **Include anything I missed.** Where this spec is silent, incomplete or wrong
   for a production ERP in 2026, add what is needed. Record each addition in
   `docs/DECISIONS.md` (one line: what, why).
3. **Add features based on today's tech growth.** Prefer current, well-supported
   approaches (offline-first PWAs, real-time sockets, mobile money, e-invoicing,
   AI copilots, passkeys) over legacy patterns. §11 is a minimum, not a ceiling.
4. **Answers must be concise.** Chat replies: short bullets, no restating the
   question, no pasting whole files into chat. Put detail in docs, not chat.
5. **Do not waste tokens/credits.**
   - Read only what you need; don't re-read files you just read or edited.
   - Batch independent tool calls in one turn.
   - Search with grep/glob before opening big files; read slices, not wholes.
   - Run focused tests for what changed; the full suite only at phase end.
   - Don't spawn sub-agents unless a task truly needs parallel breadth.
   - Don't narrate options you won't pursue. Recommend, don't survey.
   - Keep `docs/STATE.md` current (phase, done, next, blockers) so a new session
     resumes by reading one file instead of re-exploring.
6. **Follow-up questions:** ask only when a decision is genuinely the owner's
   (money, legal, branding, paid services, real credentials). Batch questions,
   max one round per phase. Otherwise apply the best decision, log it, move on.
7. **Install what you need, when you need it.** Check what's already installed
   first (`winget list`, `--version`). Install missing tools with `winget` /
   package managers when a phase needs them, not all up front. Tell the owner
   before any system-wide or paid install. Record every tool and version in
   `docs/REQUIREMENTS.md`.
8. **Ship vertical slices that run.** Each phase ends with something the owner can
   click through, plus passing tests and updated docs. No "foundation forever".
9. **Never** commit secrets, use real customer data in dev, create real accounts on
   third-party services, or make purchases without owner approval.

### 0.1 Reference: earlier attempt

`../Complete ERP/` holds an earlier Django build (read `ERP_POS_MASTER_BUILD.md`,
`docs/PHASE_STATUS.md`, `AGENTS.md`). **Do not modify it and do not continue it.**
Mine it for requirements, invariants and its permission matrix. Learn from its
main failure: many phases were spent on accounting before any POS sale, offline
sync or store connection existed. This build puts a working POS sale in Phase 2.

---

## 1. What to build

A modular, offline-first **ERP + POS** platform with real-time online-store sync.

- **Primary target:** retail and wholesale of general goods and groceries,
  companies with their own warehouses and several outlets.
- **Must also fit** restaurants, manufacturing, pharmacies, sales/distribution,
  technology/electronics, fashion and boutiques, service providers with packages,
  and more, through **installable industry modules**.
- **One ERP per account** (tenant). An account has one or more legal entities
  (optional) and many **branches**. **Each branch can have different industry
  modules installed**, e.g. HQ = Wholesale, Branch A = Grocery, Branch B =
  Restaurant, Branch C = Pharmacy, all in the same ERP with shared master data.
- **Runs completely locally with the internet off**, then syncs when it returns.
  Auto or manual sync, choosing *what* to sync.
- **Deploys three ways from one codebase:** cloud-hosted, centralized on-premise
  server, or standalone/branch-local. Documented install for each.
- Default locale: **Ghana, GHS, Africa/Accra, English**. Multi-currency,
  multi-language and multi-country must be configuration, not code changes.

---

## 2. Stack (decided, with reasons; deviate only with a logged reason)

| Layer | Choice | Why |
|---|---|---|
| Backend / API | **Laravel 13 (PHP 8.4+)** | Owner already builds in Laravel; mature queues, auth, events |
| Back office UI | **Filament v5** (Livewire 4) | Fast, consistent admin/CRUD for every department |
| POS, KDS, handheld, kiosk UI | **React + TypeScript + Vite PWA** | Must work fully offline in the browser; Livewire can't |
| POS local storage | **IndexedDB (Dexie)** in PWA; **SQLite** in desktop shell | Till keeps selling even if branch server is down |
| Desktop POS shell | **Tauri 2** | Small installer; ESC/POS printers, cash drawer, scales, customer display |
| Android handhelds | PWA first; **Capacitor** wrapper if hardware scanning needs it | Warehouse/van sales devices |
| Database (server) | **PostgreSQL 17** everywhere (cloud, central, branch node) | One SQL dialect, JSONB, row locking, logical replication options |
| Real-time | **Laravel Reverb** (WebSockets); SSE/poll fallback | Live stock, prices, orders across POS/online/back office |
| Queue/cache | Redis where available; database driver fallback | Works on small local installs |
| App server | **FrankenPHP** (Octane) single binary for local nodes; Nginx/Caddy + PHP-FPM or FrankenPHP for cloud | Simple Windows local install, fast |
| Packaging | Docker Compose (cloud/central); Windows installer (local node + POS shell) | One command per deployment mode |
| Search | Postgres full-text + trigram; Meilisearch optional | Fast product lookup offline and online |
| Tests | Pest (PHP), Vitest + Playwright (JS) | Required for money, stock, sync, auth |
| AI | Pluggable provider: Claude API (default, e.g. `claude-sonnet-5-5`), local model via Ollama when offline/private | §10 |

**Not supported:** cPanel shared hosting for the full system (no WebSockets/daemons).
Document it as unsupported; recommend a VPS instead.

---

## 3. Architecture

### 3.1 Node topology

```
            ┌──────────── Cloud / Central Server (HQ node) ────────────┐
            │ Postgres · API · Reverb · Back office · Store connectors │
            └───────▲───────────────▲───────────────────▲──────────────┘
                    │ sync          │ sync              │ webhooks/API
          ┌─────────┴──────┐ ┌──────┴─────────┐   ┌─────┴──────────┐
          │ Branch node A  │ │ Branch node B  │   │ Online store(s)│
          │ (local server) │ │ (local server) │   │ Woo/Shopify/own│
          └──▲────▲────▲───┘ └──▲─────────────┘   └────────────────┘
             │    │    │        │ LAN
           POS1 POS2 Handheld  POS1   (each till: offline cache + queue)
```

- **Every node runs the same codebase**; a node's role (`hq`, `branch`,
  `standalone`) is configuration.
- **Standalone mode:** one PC is both HQ and branch, fully offline, optional cloud
  later.
- **Centralized mode:** one on-prem server, all branches online via LAN/VPN; tills
  still keep an offline queue.
- **Cloud mode:** HQ in the cloud; branches either connect directly (thin) or run a
  branch node (recommended where connectivity is unreliable).

### 3.2 Data rules

- IDs: **UUIDv7** everywhere (generated offline without collision). Human numbers
  (invoice no., receipt no.) are **per-device prefixed sequences**, e.g.
  `ACC-B01-T02-000123`, never assigned centrally at sale time.
- Money: **integer minor units** (pesewas) + currency code. Never floats.
  Quantities: decimal with per-UOM precision (weighted goods need 3 dp).
- Stock is an **append-only movement ledger**; on-hand is derived. Never sync
  "quantity = N" overwrites; sync movements.
- Posted documents (sales, receipts, journals, GRNs) are **immutable**; corrections
  are reversals/credit notes with reason and approver.
- Every mutation runs through a **domain service** in one DB transaction that also
  writes the audit row and the **outbox** event.
- All rows carry `tenant_id`, `branch_id` (where relevant), `origin_node`,
  `version`, `created_by`, `created_at` (device time) and `received_at` (server time).

### 3.3 Module system

- Each module is a self-contained package: `modules/<Name>/` with `module.json`
  manifest (name, version, dependencies, permissions, migrations, menu entries,
  POS extensions, settings schema, sync entities, seeders).
- **Core** (always installed) vs **Department modules** vs **Industry modules**.
- Install/enable/disable **per branch** at setup and later from Settings → Modules
  (owner/IT level only, audited). Disabling hides UI and blocks new writes; it
  never deletes data.
- Modules extend core through events, hooks and registered extension points
  (product fields, POS cart rules, receipt blocks, report widgets). Don't fork core.
- Setup wizard (first run): deployment mode → company → branches → **modules per
  branch** → tax profile → chart of accounts template → users → hardware → sync
  settings → sample data (optional).

---

## 4. Offline-first sync (core requirement)

### 4.1 Behaviour

- POS and branch nodes **work fully offline**: sell, return, receive stock, count,
  transfer, clock in/out, take payments (cash, card on standalone terminal,
  mobile money with manual reference).
- When the connection returns, sync runs **automatically** (if enabled) or
  **manually** from the Sync Center.
- **Selective sync**: the user picks entity groups and directions, e.g.
  - Products: names, descriptions, barcodes, images, categories, UOMs
  - Prices / price lists / promotions / taxes
  - Stock movements / stock levels snapshot
  - Customers / loyalty / gift cards / credit balances
  - Sales / returns / payments / shifts / cash-ups
  - Purchases / GRNs / suppliers
  - Users / roles / permissions
  - Settings / modules
  - Online store orders, stock, prices
- Per group: direction (push / pull / both), mode (auto / manual / scheduled),
  priority, and **bandwidth mode** (skip images on metered links).
- **Preview before applying** manual pulls: a diff ("142 price changes, 3
  conflicts") with accept/reject per item or group.

### 4.2 Mechanism

- Outbox/inbox change log per node with monotonic per-node sequence + vector of
  last-seen sequences per peer. Batched, compressed, resumable, idempotent
  (event ID dedupe). Signed with node keys (mTLS or HMAC).
- **Conflict policy per entity, configurable, with safe defaults:**
  - Master data (product name, price, tax): **HQ wins** unless the field is marked
    branch-overridable (e.g. branch price list).
  - Stock: movements merge (no conflict possible); negative-stock alerts after merge.
  - Sales/payments: append-only, never conflict.
  - Customers: field-level last-writer-wins with conflict log; credit limit is HQ-only.
  - Anything unresolved → **Conflict queue** for a human.
- Oversell protection for online orders while a branch is offline: per-channel
  **safety stock buffer** and stock reservation; alerts if the merge goes negative.
- Sync Center UI: status per node/till, last sync, queue size, errors, conflicts,
  retry, force full resync, export queue to file (USB fallback for very poor links).
- Offline auth: cached hashed credentials/PIN with expiry (configurable, e.g. 72 h),
  revocation applied at next sync. Local data encrypted at rest.

### 4.3 Tests (mandatory)

Two tills selling the last unit offline; price change at HQ while branch sells at
the old price; duplicate replay; partial batch failure; clock skew; till
reinstall mid-queue; 7 days offline then sync; online order during branch outage.

---

## 5. Real-time and online store

- Reverb channels per tenant/branch: stock changes, price changes, new online
  orders, KDS tickets, approvals, chat/notifications. POS screens and the store
  update in seconds when online.
- **Connectors:** WooCommerce, Shopify and a built-in lightweight storefront (optional
  module), plus a generic REST + webhook connector. Each connector:
  - pushes products, images, prices, stock (per channel, per warehouse allocation)
  - pulls orders → ERP sales orders with reservation, fulfilment, invoicing
  - syncs order status, refunds, customers
  - idempotent webhooks with signature verification; retry with backoff; dead-letter
    queue; reconciliation report.
- Omnichannel: click & collect (BOPIS), ship-from-store, reserve online/pay in
  store, endless aisle (POS sells warehouse stock for delivery), returns anywhere.
- Marketplaces/delivery (connector stubs, flag off until configured): Jumia,
  Glovo, Bolt Food, WhatsApp catalog.

---

## 6. Core and department modules (all installed with Core unless marked optional)

1. **Company & Setup:** tenants, legal entities, branches, warehouses, zones/bins,
   tills, devices, fiscal years, currencies, taxes, numbering, branding, languages.
2. **Products & Catalog:** variants (matrix), multi-UOM with conversions
   (carton/pack/piece; buy in cartons, sell in pieces), multiple barcodes per UOM,
   weighted/price-embedded barcodes, bundles/kits, serial and batch/lot, expiry,
   images, attributes, brands, categories, supplier items, label printing.
3. **Pricing & Promotions:** price lists (retail, wholesale, tiers by quantity,
   customer-specific, branch, channel), scheduled prices, promotions (BOGO, mix &
   match, happy hour, coupon, threshold), loyalty, gift cards, vouchers,
   price-override rules.
4. **Inventory & Warehouse (WMS):** multi-warehouse, bins, receiving, put-away,
   pick/pack/ship, wave picking, transfers (with in-transit), cycle counts and
   blind counts, adjustments with reasons, FEFO/FIFO, valuation (FIFO / weighted
   average, configurable), landed costs, reorder points, min/max, ABC analysis,
   damaged/expired write-off, handheld scanning.
5. **Purchasing / Procurement:** requisitions, RFQs, purchase orders, approvals by
   amount, GRN, 3-way match, supplier invoices, returns to supplier, supplier
   portal (optional), supplier scorecards.
6. **Sales (B2B / wholesale):** quotations, sales orders, credit limits and holds,
   delivery notes, invoices, customer statements, aged receivables, sales reps,
   commissions, route/van sales (Distribution module).
7. **POS:** see §7.
8. **CRM & Customers:** profiles, segments, purchase history, loyalty, credit
   accounts, consent tracking, campaigns (SMS/WhatsApp/email), feedback, tickets.
9. **Finance & Accounting:** chart of accounts templates, double-entry GL
   auto-posting from every module, AR/AP, bank and mobile-money accounts, bank
   reconciliation (statement import), cash management, expenses, budgets, cost
   centres per branch/department, multi-currency revaluation, period close and
   lock, fixed assets and depreciation, financial statements, tax reports.
10. **HR & Payroll:** employees, departments, contracts, attendance (POS clock-in,
    biometric/geofenced mobile), shifts/rosters, leave, payroll with configurable
    statutory rules (Ghana SSNIT tiers and PAYE as a seeded, accountant-verifiable
    profile), payslips, loans/advances, performance, recruitment (optional),
    employee self-service.
11. **Logistics & Delivery (optional):** delivery orders, riders/drivers, routes,
    proof of delivery (photo/signature/OTP), fleet and fuel logs.
12. **Assets & Maintenance (optional):** equipment register, maintenance schedules.
13. **Projects & Tasks (optional):** for service and technology companies.
14. **Document Management:** attachments on every record, templates, e-signature.
15. **Helpdesk / Customer service (optional):** tickets, SLAs, warranty claims.
16. **Reporting & BI:** report builder, scheduled reports by email/WhatsApp,
    dashboards per role, exports (CSV, Excel, PDF), drill-down to source document.
17. **AI Analytics & Copilot:** see §10.
18. **Notifications:** in-app, email, SMS (Ghana sender-ID registration noted),
    WhatsApp Business, push; templates editable in admin.
19. **Audit & Compliance:** tamper-evident audit log (hash chain), activity
    timeline per record, data export/erasure requests (Ghana Act 843, GDPR-ready).
20. **Integrations & API:** REST + webhooks, API keys with scopes, OpenAPI docs,
    import/export wizards (CSV/Excel with validation preview), accounting export
    (QuickBooks/Xero/Sage format), plugin SDK.
21. **Administration:** users, roles, security levels, devices, sessions, modules,
    sync, backups, system health, licence/subscription (for SaaS mode).

---

## 7. POS requirements

- Keyboard-first and touch-friendly; sub-second scan-to-cart; works offline.
- Barcode scan, search, quick keys/favourites, weighted items (scale integration),
  price-embedded barcodes, multi-UOM selection, serial/batch capture.
- Customer lookup, wholesale/retail price switch by customer, credit sales within
  limit.
- Split payments: cash, card (integrated terminal or manual), **mobile money (MTN
  MoMo, Telecel Cash, AT Money)** via Paystack/Hubtel, QR pay, GhanaPay, gift card,
  loyalty points, store credit, cheque, bank transfer.
- Hold/recall carts, layaway, quotations to sale, returns/exchanges with receipt or
  lookup, refunds by original tender.
- Discounts and overrides governed by role limits; supervisor approval by PIN,
  card or remote push approval.
- Shifts: open float, cash drops, pay-ins/outs, X/Z reports, blind cash-up,
  over/short tracking.
- Receipts: thermal (ESC/POS), A4 invoice, **digital receipt via SMS/WhatsApp/email**,
  QR on receipt; reprints audited.
- Hardware: receipt printers, cash drawers, barcode scanners, scales, customer
  display, label printers, card terminals; per-till hardware profiles.
- Modes: standard till, **self-checkout kiosk**, **mobile POS** (line-busting,
  market stalls), **scan-and-go** (optional), table-service (Restaurant module).
- Fiscal: **Ghana GRA E-VAT / e-invoicing** integration as a pluggable fiscal adapter
  (queue invoices offline, submit on reconnect); VAT/NHIL/GETFund/COVID levy rules
  as a configurable tax profile that an accountant must verify before go-live.

---

## 8. Industry modules (install per branch)

| Module | Must include |
|---|---|
| **Retail & Wholesale** (default) | tiered/wholesale pricing, multi-UOM, credit customers, B2B orders, van sales hook |
| **Grocery / Supermarket** | scales and weighted barcodes, expiry/batch with FEFO, markdowns for near-expiry, fresh-produce shrink, ESL (electronic shelf labels) hook |
| **Restaurant / Café / Bar** | floor plan and tables, modifiers, courses, split bills, KDS (kitchen display), recipes/BOM with ingredient deduction, waste, reservations, delivery-aggregator orders, tips |
| **Pharmacy** | batch/expiry mandatory, prescriptions, patient profiles, controlled-drug register, drug-interaction/allergy warnings (data source pluggable), generic substitution, NHIS claims hook, Ghana FDA / Pharmacy Council compliance fields |
| **Manufacturing** | multi-level BOM, routings, work centres, work orders, MRP, material issue/backflush, by-products, scrap, QC inspections, production costing |
| **Fashion & Boutique** | size/colour matrix, seasons/collections, lookbook, consignment, alterations, style-based reporting |
| **Electronics / Technology** | serial/IMEI tracking, warranties, repairs/RMA job cards, trade-ins, subscriptions/licences |
| **Services & Packages** | service catalogue, packages/bundles, memberships, subscriptions with recurring billing, appointments/bookings, staff calendars, commissions (salons, gyms, clinics, consultancies) |
| **Sales & Distribution** | routes, territories, field reps app, van stock, order-taking offline, GPS check-ins, collections |
| **Hospitality / Hotels** (optional, later) | rooms, folios, POS charge-to-room |
| **Agriculture / Agro-dealers** (optional, later) | seasonal credit, input bundles |

Each module declares its permissions, menus, POS extensions, reports, seed data and
tests. A branch can run several modules (e.g. Grocery + Restaurant food court).

---

## 9. Security levels, roles and permissions

### 9.1 Security levels (hierarchical ceiling)

| Level | Name | Typical scope |
|---|---|---|
| L7 | Platform owner | SaaS operator only; no tenant business data by default |
| L6 | Account owner / Director | all entities and branches |
| L5 | Executive / Finance controller | company-wide, approvals |
| L4 | Branch / Department manager | own branch/department |
| L3 | Supervisor | own shift/team; overrides within limits |
| L2 | Staff / Cashier / Storekeeper | own tasks, own till |
| L1 | Restricted / Temp / Auditor (read-only) | named screens, time-boxed |
| L0 | External (customer, supplier, rider portal) | own records only |

### 9.2 Roles (seeded, editable)

Owner, General Manager, Accountant, Finance Manager, Auditor (read-only), HR
Manager, Payroll Officer, Purchasing Officer, Purchase Approver, Warehouse
Manager, Storekeeper, Picker/Packer, Branch Manager, Supervisor, Cashier, Sales
Rep, Van Sales Rep, Customer Service, Marketing, E-commerce Manager, Delivery
Rider, IT Admin (technical only, **no implicit business permissions**), plus
module roles (Pharmacist, Pharmacy Technician, Chef, Waiter, Production Manager,
QC Inspector, Technician, Stylist, etc.).

### 9.3 Controls

- RBAC + scopes (entity, branch, warehouse, till, department, cost centre) +
  numeric limits (max discount %, refund amount, PO approval amount, credit
  override).
- Segregation of duties rules (e.g. creator ≠ approver; cashier can't approve own
  refund).
- MFA (TOTP) for L3+; **passkeys** supported; optional SSO (Google/Microsoft).
  Fast POS login by PIN or badge, still tied to a named user.
- Device registration and approval; remote wipe/revoke of a till.
- Temporary elevated access with expiry and reason.
- Rate limiting, lockout, session timeout, IP allow-lists for admin (optional).
- Encryption: TLS everywhere, sensitive columns encrypted, local node disk/DB
  encrypted, backups encrypted.
- Never store card data (PCI SAQ-A posture); tokens only.
- OWASP ASVS L2 as the target; security tests in CI; dependency scanning.

---

## 10. AI analytics and copilot

- **Forecasting:** demand per SKU/branch (seasonality, holidays, paydays), auto
  reorder suggestions, cash-flow forecast.
- **Inventory intelligence:** dead stock, overstock, expiry risk, transfer
  suggestions between branches, optimal min/max.
- **Pricing:** margin alerts, markdown suggestions, competitor price import,
  promotion uplift analysis.
- **Customers:** RFM segmentation, churn risk, next-best offer, basket analysis.
- **Loss prevention:** anomaly detection on voids, refunds, discounts, no-sales,
  cash over/short, sweethearting patterns; risk score per cashier/shift.
- **Copilot ("Ask your business"):** natural-language questions answered from
  permitted data only (respects the user's RBAC scope), with charts and links to
  source records. Tool-based (read-only queries via safe, parameterized report
  functions; never raw SQL from the model).
- **Automation:** supplier invoice OCR to draft bill, product description and image
  alt-text generation for the online store, auto-categorisation, email/WhatsApp
  draft replies.
- **Governance:** provider switchable (cloud LLM / local Ollama / off); PII
  redaction before cloud calls; per-tenant monthly cost cap; prompt/response
  audit log; AI suggestions never auto-post financial transactions without
  human approval.
- Offline: classical models (forecasting, anomaly) run locally on the node; LLM
  features degrade gracefully when offline.

---

## 11. Modern features checklist (minimum; add more)

PWA install and push notifications · light/dark/system themes · WCAG 2.2 AA ·
responsive mobile-first back office · multi-language (English first; Twi/French
ready) · global search (Cmd/Ctrl-K) · real-time dashboards · WhatsApp ordering and
receipts · QR menus/catalogues · digital receipts · loyalty wallet passes
(Apple/Google) · e-invoicing adapter · open banking/mobile-money reconciliation ·
webhooks and public API · CSV/Excel import with preview · audit hash chain ·
observability (structured logs, health endpoint, error tracking such as Sentry
optional, uptime) · automated encrypted backups with **tested restore** ·
zero-downtime deploys (cloud) · feature flags · in-app help and onboarding tours ·
customer-facing display · ESL and RFID hooks · carbon/sustainability report
(optional) · data retention policies.

---

## 12. Non-functional targets

- POS: scan-to-cart < 150 ms, checkout complete < 1 s offline, runs on a low-end
  Windows PC (4 GB RAM) or Android tablet.
- Back office pages < 2 s on 3G-class links; images lazy-loaded and resized.
- Branch node handles 20 tills; cloud HQ scales horizontally (stateless app,
  queue workers, Reverb).
- Sync: 10,000 queued events reconcile in < 2 min on a normal link.
- Availability (cloud): 99.9 % target; RPO ≤ 15 min, RTO ≤ 4 h documented.
- All money/stock/sync/auth code covered by tests; CI must pass before merge.

---

## 13. Documentation to produce (keep current every phase)

- `README.md`: overview, quick start.
- `CLAUDE.md`: standing rules for future agent sessions (short; points here).
- `docs/STATE.md`: current phase, done, next, blockers (resume file).
- `docs/DECISIONS.md`: decision log (incl. "things I added that weren't asked").
- `docs/REQUIREMENTS.md`: hardware, OS, software versions per deployment mode.
- `docs/install/standalone.md`: single PC, fully offline (Windows installer +
  manual steps).
- `docs/install/branch-node.md`: branch server + tills + connecting to HQ.
- `docs/install/central-server.md`: on-prem central server (Windows Server or
  Ubuntu), LAN/VPN, backups.
- `docs/install/cloud.md`: VPS/cloud (Docker Compose, domain, TLS, Reverb, workers,
  scheduler, backups, monitoring); a managed-cloud variant.
- `docs/install/pos-devices.md`: PWA, Tauri desktop, Android, printer/scanner setup.
- `docs/sync.md`: how sync works, settings, conflicts, troubleshooting.
- `docs/modules.md`: module list, how to install per branch, how to build a module.
- `docs/security.md`: levels, roles matrix, MFA, devices, incident response.
- `docs/api.md` + generated OpenAPI.
- `docs/user-manual/`: per department and per module, task-oriented, screenshots.
- `docs/admin-manual.md`: setup wizard, users, backups/restore, upgrades, rollback.
- `CHANGELOG.md`.

Each install guide: prerequisites → exact commands in order → verify steps →
upgrade → backup/restore → uninstall → troubleshooting.

---

## 14. Phases (one at a time; each ends runnable, tested, documented)

| # | Phase | Done when |
|---|---|---|
| 0 | **Discovery & scaffold:** read all, confirm env, install base tools, repo, CI, `CLAUDE.md`, `docs/STATE.md`, Phase-0 questions (§15) | app boots locally; CI green |
| 1 | **Core platform:** tenancy, entities/branches/warehouses, users, security levels/roles/scopes, MFA, audit chain, module system + setup wizard, settings | wizard installs modules per branch; RBAC tests pass |
| 2 | **Catalog + inventory ledger + basic POS (online):** products, UOM, barcodes, price lists, stock movements, POS sale with cash/MoMo, receipt | owner completes a sale and sees stock drop |
| 3 | **Offline POS + sync engine:** IndexedDB, queue, node sync, selective/auto/manual sync, Sync Center, conflict queue | §4.3 tests pass; sale offline → syncs |
| 4 | **Real-time + online store:** Reverb, WooCommerce + Shopify connectors, built-in storefront (optional), reservations | price change appears on POS and store live |
| 5 | **Purchasing + WMS:** POs, GRN, transfers, counts, bins, handheld PWA, reorder | receive → put-away → transfer → count end-to-end |
| 6 | **Sales B2B + CRM + loyalty/promotions** | wholesale order to invoice to statement |
| 7 | **Finance & accounting:** GL auto-posting, AR/AP, banking/MoMo reconciliation, tax profile, statements, period close | trial balance ties to sub-ledgers |
| 8 | **HR & payroll + attendance** | payroll run with payslips on a seeded profile |
| 9 | **Industry modules wave 1:** Grocery, Restaurant (KDS), Pharmacy | each installable per branch with its tests |
| 10 | **Industry modules wave 2:** Manufacturing, Fashion, Electronics, Services & Packages, Distribution | as above |
| 11 | **AI analytics & copilot** | forecasts, anomaly alerts, copilot answering scoped questions |
| 12 | **Reporting/BI, notifications, integrations/API, import wizards** | scheduled report delivered; OpenAPI published |
| 13 | **Hardening:** security review, performance/load, accessibility, backup/restore drill, e-invoicing adapter, installers for all modes | launch checklist passes |
| 14 | **Pilot & launch:** data migration, training material, go-live runbook, rollback plan | pilot branch live |

Phase order may change only with a logged reason. Don't start a phase until the
previous one's "done when" is demonstrated.

---

## 15. Phase-0 questions for the owner (ask once, batched; defaults in brackets)

1. Company name, branches and which modules each branch needs? [demo data]
2. Number of users/tills per branch? [15 users, 3 branches, 2 tills each]
3. First deployment mode? [cloud HQ + branch nodes]
4. Online store platform in use? [WooCommerce]
5. Payment providers? [Paystack + Hubtel MoMo; test keys only until approved]
6. Is GRA E-VAT integration needed at launch? [yes, adapter with sandbox]
7. Brand assets (logo, colours)? [neutral placeholder theme]
8. AI provider and monthly budget? [Claude API, capped; local fallback]
9. SaaS (many companies) or single company? [single tenant now, multi-tenant-ready]

Everything else: decide, log in `docs/DECISIONS.md`, proceed.

---

## 16. End-of-task checklist (every task)

1. Works and is demonstrated (command or click path given in ≤5 lines).
2. Tests added/updated and passing (state counts briefly).
3. Docs, `CHANGELOG.md`, `docs/STATE.md` updated.
4. Anything missed or now needed → built now, or a feature flag left **off** plus a
   line in `docs/DECISIONS.md` (no half-built "on" features, no orphan
   permissions/env keys).
5. Reply to owner: what was done, how to verify, what's next, any decision needed.
   Concise.
