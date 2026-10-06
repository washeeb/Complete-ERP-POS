# Changelog

## [0.7.0] — 2026-10-11 · Phase 6b promotions, loyalty, gift cards and returns
### Added
- **Promotions** (Pricing & tax → Promotions): percent or amount off, buy X get Y, bundles / mix & match, spend-X basket offers, coupons; by dates, days, happy-hour times, branch and price list. The best single promotion per item, then basket offer and one coupon on top.
- One promotion engine in PHP and on the till, proven identical by shared test vectors; works offline, and the server re-checks online sales.
- **Till discounts**: line discount or price override and a sale discount, within role limits (supervisor 10 %, manager 25 %); a supervisor approves by PIN, offline too.
- **Loyalty**: 1 point per GHS 1, pay with points from 500 (100 points = GHS 1), 12-month expiry; synced to every branch.
- **Gift cards and store credit** (Sales → Gift cards & credit) as till tenders; expire 12 months after last use.
- **Returns** at the till (F8): by receipt within 30 days, refunded to the original tenders; without a receipt, store credit with a supervisor PIN.
- **Take account payments** at the till (counted in the shift cash).
- Receipts show promotions, "You saved", points and RETURN/REFUND; new layout: logo space, numbered item columns (SN, Item, Qty, Price, Total) running margin to margin, and a QR code of the receipt number (scan it on the Return screen).
- Commands: `erp:expire-balances` (nightly); `erp:receivables-check` also checks points and card balances; `erp:demo-sales` adds sample promotions.
- Docs: promotions, loyalty and returns guide; till API for promotions, returns and account payments.
## [0.6.0] — 2026-10-10 · Phase 6a customers and order-to-cash
### Added
- **Customers** (Sales → Customers): profile, price list, recorded marketing consent (who, when, how), and head-office credit (limit, terms, hold, opening balance). The customer page shows balance, overdue amount, available credit and open items.
- **Quotations → orders**:
  - priced from the customer's price list;
  - the credit check puts an order on hold;
  - release by someone else within their override limit.
- **Deliveries**: partial or full; each one raises its **invoice** automatically, due after the customer's terms.
- **Payments**:
  - settle the oldest invoices first;
  - leftover money becomes account credit, used on the next invoice.
- **Credit notes**: optionally take the goods back.
- **Statements** and **aged receivables**.
- A4 print/PDF pages for quotation/order, tax invoice, statement and payment receipt.
- **Till**: customer lookup (F4, works offline) with the customer's price list, and the **On account** tender. A supervisor approves by PIN when a sale goes over the limit; offline credit sales are kept and flagged.
- Sync: *customers* group (field-level merge with a conflict log) and *receivables* group, both relayed between branch servers.
- Commands: `erp:receivables-check`, `erp:demo-sales`.
- Docs: customers, wholesale sales and receivables guide; till customer API.
### Fixed
- A newly installed owner now gets the owner role's approval limits (PO approval, credit override).

## [0.5.0] — 2026-10-09 · Phase 5 purchasing and warehouse
### Added
- **Suppliers**: contacts, terms, lead time; products supplied with purchase unit, supplier code, last cost, minimum order, preferred.
- **Purchase orders**:
  - draft → submit → approve or send back;
  - the creator never approves; tiered approval limits (officer GHS 5,000, managers GHS 20,000, finance/GM/owner unlimited);
  - receive, close short, cancel;
  - tax worked out from each product's tax group.
- **Goods received (GRN)**:
  - posts at cost (weighted-average stock valuation);
  - over-deliveries above 5 % wait for a manager (accept or refuse);
  - a posted GRN is reversed, never edited.
- **Bins**:
  - switched on per warehouse, with a receiving bin;
  - put-away and move stock;
  - pick order for sales and transfers;
  - bin contents shown.
- **Transfers**: dispatch, in transit, receive with differences flagged for review.
- **Stock counts**: blind by default, frozen expected quantities, entered on screen or handheld, posted by a second person.
- **Reorder levels** (min/max) and **What to order**: suggestions in supplier cartons that count open orders and transfers in transit; one-click draft orders per supplier.
- **Warehouse handheld**: enrol a scanner phone as a handheld; PIN sign-in for storekeepers; receive by scanning, put away, receive transfers, count.
- Document numbers per server: PO-HQ-000001, GRN-…, TRF-…, CNT-….
- Sync: new groups *purchasing* and *warehouse*; head office relays stock and warehouse events between branch servers.
- Settings: over-delivery tolerance (%).
- `erp:demo-purchasing` sample suppliers, head-office bins and reorder levels.
- Docs: purchasing and warehouse guide; handheld API.
### Changed
- The device enrolment screen says "device" (tills and handhelds).
- `erp:stock-check` also checks bin levels, and that bin-managed warehouses equal the sum of their bins.
### Fixed
- A timing-dependent test of the 72-hour offline sign-in boundary.

## [0.4.0] — 2026-10-08 · Phase 4 live updates and online store
### Added
- Live updates over WebSockets (Laravel Reverb). Tills pick up price and stock changes within seconds ("Connected · live"), with polling as a fallback.
- **Online → Channels**: built-in shop, WooCommerce, Shopify and generic REST connectors.
  - Encrypted secrets, never shown again; webhook address; safety stock per channel.
  - Activate, pause, compare with store (reconciliation), retry failed updates.
  - Marketplaces listed as planned.
- **Online → Online orders**: orders from every channel with reserved stock, Mark ready, Hand over (records payment and issues stock), and Cancel (releases stock); scoped by branch.
- **Built-in online shop** (Storefront module, per branch) at `/shop/{address}`: live prices and availability, order and pickup checkout, and a confirmation page.
- Store webhooks: signed, deduplicated, stored before processing. Store pushes: retry with backoff, then a review item. Reconciliation every 15 minutes.
- Review queue types: oversold online order, store total differs, store update failed, store order not processed.
- Permissions: `orders.view`, `orders.manage`, `channels.manage`, added to the standard roles (`php artisan db:seed` after upgrading).
- Commands: `erp:relay`, `erp:channels:reconcile`.
- Docs: online store and live updates guide; API for webhooks and live channels.

## [0.3.0] — 2026-10-07 · Phase 3 offline tills and server sync
### Added
- Tills keep working without their server: offline PIN sign-in (signed, expiring offline grants), cached catalog, offline shifts and sales in an ordered queue, receipts marked "recorded offline", automatic sending in device-key-signed batches, "Retry refused", "Export unsent sales".
- Offline sales are kept as they happened and flagged in a new **Review queue** (old price, expired or revoked sign-in, wrong clock, negative stock, refused items); resolved with a note.
- Branch servers: pairing with head office (`erp:node:add-branch`, `erp:node:join`), automatic two-way sync every minute (`erp:sync`), HQ-owned master data read-only on branches, per-group Automatic / Manual (preview) / Off, full resync, USB sync files.
- **Sync Center**: peer status, queues, errors, sync now, review incoming, export/import, disable/enable, add branch server, import till file.
- Receipt series letter per till device (HQ-T01A-000123) so numbers never collide after a reinstall.
- Settings: how long an offline till sign-in lasts.
- Docs: offline and sync guide, branch server install, cashier offline section, API for the queue and server sync, security notes.
### Fixed
- Starter data and seeded roles now reach branch servers (they were created without sync events).
- The till treats proxy errors (502/503/504) from a stopped server as "offline".

## [0.2.0] — 2026-10-06 · Phase 2 catalog, stock and first till sale
### Added
- Catalog: products with SKU, category, brand, base unit, extra units (carton = 24), barcodes per unit, tax group; categories, brands, units of measure.
- Prices: Retail and Wholesale lists, prices per unit, quantity tiers, dated promotions; tax rates and tax groups with a recorded accountant check (Ghana starter set marked unverified).
- Stock: append-only stock ledger, stock on hand per warehouse, opening balances and adjustments with reasons; `erp:stock-check`.
- Till app: enrolment by one-time code, PIN sign-in, open shift with float, scan/search, units, Retail/Wholesale switch, split cash / mobile money / card payments, 80 mm receipt, blind shift close with over/short; unsent sales kept on the till with retry.
- Sale API: idempotent posting with server-side recalculation of every price, tax and total.
- Back office: Till sales and Till shifts; settings for receipt footer and negative-stock blocking.
- `erp:demo-catalog` sample range (21 Ghanaian grocery/wholesale items).
- Docs: pricing and tax rules, cashier guide, catalog and stock guide, API for the till.
### Fixed
- Back-office columns that showed blank or zero values (Roles level, Devices kind/status, Tax rates) because their display callbacks did not receive the value.

## [0.1.0] — 2026-10-05 · Phase 1 core platform
### Added
- Company structure: companies, legal entities, branches, warehouses, tills, devices (UUIDv7 ids, origin node and version on every row).
- Access control: security levels L0–L7, 22 seeded roles, grants scoped to company / legal entity / branch / warehouse / till with numeric limits and optional expiry; anti-escalation rules; IT Admin technical-only.
- Tamper-evident audit log (HMAC chain, append-only at the database, secrets redacted) with *Verify chain* and `erp:audit-verify`; transactional outbox for sync.
- Module system (`server/modules/*/module.json`): Core plus 14 planned modules, enabled/reserved/off per branch.
- First run: `erp:audit-key`, `erp:install`, then a back-office setup wizard (deployment mode, company, branches, tills, modules per branch).
- Back office: Branches (warehouses, tills), Users (access grants, POS PIN), Roles, Devices (one-time enrolment codes, revoke), Audit log, Modules, Settings.
- Sign-in security: two-factor required from L3 (authenticator app with recovery codes, or passkeys with user verification); audited profile and 2FA changes.
- Till API: device enrolment, PIN sign-in on enrolled devices with lockout, `me`, logout.
- Docs: security, modules, API, administrator manual.

## [0.0.1] — 2026-10-05 · Phase 0 scaffold
### Added
- Monorepo: `server/` (Laravel 13, Filament 5 back office at `/admin`, Pest on PostgreSQL 17) and `pos/` (React + TypeScript PWA shell with connection status).
- CI workflow: formatting, dependency audits, server and POS tests, POS build.
- Docs: README, CLAUDE.md, STATE, DECISIONS, REQUIREMENTS.
