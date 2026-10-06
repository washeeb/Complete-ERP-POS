# State (resume file)

**Phase:** 6 — Sales B2B + CRM + loyalty/promotions · **Status:** 6a done on branch `phase-6-sales-crm` (PR to `main`); next is 6b
**Updated:** 2026-10-10 · Repo: https://github.com/washeeb/Complete-ERP-POS (private) · Phases 1–5 merged (PRs #1–#6)

## Phase 6a — done (203 Pest + 18 Vitest + 3 Playwright; browser-verified in the back office)
- **Owner answers (2026-10-10):**
  - credit over the limit or overdue → hold for approval (supervisor PIN on the till);
  - invoices are raised when goods are delivered;
  - loyalty at 1 % back (for 6b);
  - customers are synced to all branches, with field merge.
- **Done-when met in the browser:**
  - the owner made quotation QT-HQ-000001 for Asante Provisions (sample, wholesale) on screen;
  - Turn into order → SO-HQ-000001;
  - a delivery of 12 CTN was refused (only 43 in stock); 3 CTN were delivered → DN-HQ-000001 + INV-HQ-000001 (GH₵ 2,016.00, due 5 Nov);
  - a bank payment of GH₵ 1,500 was recorded;
  - the statement showed GH₵ 516.00, not yet due;
  - `erp:receivables-check`, `erp:stock-check` and `erp:audit-verify` were clean.
- **Domain:**
  - `app/Domain/Customers/{CustomerService,CustomerLedger}`;
  - `app/Domain/Sales/{CustomerOrderService,DeliveryService,ReceivablesService}`;
  - `app/Domain/Pos/SupervisorApproval`;
  - SaleService handles customer and `account`.
- **Back office:**
  - Sales → Customers, Quotes & orders, Invoices, Payments received, Aged receivables;
  - `/print/{invoice,order,statement,payment}`.
- **Till:**
  - `pos/src/customers.ts`, `screens/CustomerPicker.tsx`, the On account tab in Pay, and the CreditApproval dialog in Sell;
  - `GET /api/v1/pos/customers` (ETag).
- **Sync:**
  - groups `customers` (customer field merge in `ReplicationApplier::mergeCustomer`, `customer_ledger_batch`) and `receivables`;
  - both are relayed.
- **Fix:** a fresh-install owner now gets the role's default limits.
- **Docs:** sales-and-receivables.md, api.md (till customers), DECISIONS 6a, CHANGELOG 0.6.0.

## Next — Phase 6b (promotions, loyalty, gift cards)
- **A promotions engine identical in PHP and the till (TypeScript), with shared test vectors:**
  - percentage or amount off;
  - BOGO / buy X get Y;
  - mix & match;
  - threshold (spend X get Y);
  - happy hour (time windows);
  - coupons;
  - per branch and price list.
- **Line discounts and price overrides** within role limits (supervisor PIN).
- **Loyalty** at 1 point per GHS 1, 100 points = GHS 1, redeemable from 500 points, expiring after 12 months: earn and burn on the till, with an append-only points ledger that syncs.
- **Gift cards, vouchers and store credit** as tenders; take customer payments at the till; returns and exchanges (by receipt, refund to the original tender).
- **Done-when:** the till and server compute the same promotion totals online and offline; the loyalty balance syncs between branches.

## Owner decisions pending
- Accountant check of the Ghana tax set-up (before go-live).
- WooCommerce/Shopify: connect a real store only with the owner's keys.

## Dev environment notes
- **Test DBs:** `erp_test` (HQ) and `erp_test_node` (branch server in NodeSyncTest).
- **Local test credentials (git-ignored):**
  - `.local-secrets/dev-owner.txt`: owner email/password/TOTP secret, cashier;
  - `.local-secrets/dev-handheld.txt`: `yaw`/`kojo` PINs.
  - TOTP codes: run `php artisan tinker` with a script using `PragmaRX\Google2FA\Google2FA::getCurrentOtp()`.
- **Browser devices:** till on `localhost:5173`; handheld on `handheld.localhost:5173`; back office on `localhost:8000/admin` (the Browser pane is small — drive Filament forms with JS `input`/`change` events if clicking is awkward).
- **Sample data:**
  - `erp:demo-catalog`;
  - `erp:demo-purchasing`;
  - `erp:demo-sales` (Asante Provisions, Mama Esi Chop Bar, Kwame Mensah — all fictitious).
- **Processes** (`.claude/launch.json`): `server` (8000), `pos` (5173), `reverb` (8080), `relay`. After `git pull`, run `migrate` and `db:seed`.
- **PHP and shells:**
  - PHP is Herd's; use script files with `php artisan tinker <file>`.
  - PowerShell double-quoted strings eat backticks and `${…}`, which breaks TS template literals; use the Edit/Write tools for code.
- **Filament 5:**
  - closures name the value `$state`, the search callback `$search`, and the option label `$value`;
  - entries use `belowContent()`;
  - use `Hidden::make()` for hidden values;
  - in tests, `flushSession()` before switching users.

## Blockers
- Merge PR `phase-6-sales-crm` (6a) → `main` (asked in chat).
