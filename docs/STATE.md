# State (resume file)

**Phase:** 5 — Purchasing + WMS · **Status:** done on branch `phase-5-purchasing-wms` (PR to `main`); next is Phase 6
**Updated:** 2026-10-09 · Repo: https://github.com/washeeb/Complete-ERP-POS (private) · Phases 1–4 merged (PRs #1–#5)

## Phase 5 — done (188 Pest + 18 Vitest + 2 Playwright; browser-verified on the handheld)
- Owner answers (2026-10-09):
  - weighted-average valuation;
  - tiered PO approval (officer GHS 5k, managers GHS 20k, finance/GM unlimited; creator never approves);
  - bins switched on per warehouse;
  - 5 % over-delivery tolerance.
- Done-when met in the browser, on the handheld (`http://handheld.localhost:5173`, storekeeper Yaw):
  1. **Receive:** PO-HQ-000001 received by scanning 3 × TOM-400 → GRN-HQ-000001 posted.
  2. **Put away:** 2 CTN moved RECV → A-01.
  3. **Transfer:** TRF-HQ-000001 (Branch A → HQ) received.
  4. **Count:** CNT-HQ-000001 counted blind (47 of 48 in A-01) and submitted; posted by Kojo, a second person, giving a −1 difference.
  - Afterwards `erp:stock-check` and `erp:audit-verify` were clean.
  - The demo exposed a bin switch-on bug, which is fixed and tested (see DECISIONS).
- Domain:
  - `app/Domain/Purchasing/{SupplierService,PurchaseOrderService,GoodsReceiptService,ReorderService}`;
  - `app/Domain/Inventory/{StockProjection,BinService,TransferService,StockCountService}`;
  - `Support/DocumentNumbers`;
  - StockLedger handles bins and average cost.
- Sync: groups `purchasing` and `warehouse`; children replicated; HQ relays two-way groups (`SyncServer::relay`, `payload.relayed_from`).
- Back office:
  - Purchasing → Purchase orders, Goods received, Suppliers, Reorder levels, What to order;
  - Stock → Transfers, Stock counts, Bins.
- Handheld:
  - `WmsController` (`/api/v1/wms/*`, token ability `handheld`);
  - PosAuthService handheld login;
  - `pos/src/screens/Handheld.tsx` (device kind from enrolment).
- Demo: `erp:demo-purchasing --as=<email>` (3 sample suppliers, HQ bins A/B-01..03, reorder levels).
- Docs: purchasing-and-warehouse.md, sync.md (relay, groups), api.md (handheld), DECISIONS Phase 5, CHANGELOG 0.5.0.

## Owner decisions pending
- Accountant check of the Ghana tax set-up (before go-live).
- WooCommerce/Shopify: connect a real store only with the owner's keys.

## Dev environment notes
- Test DBs: `erp_test` (HQ) and `erp_test_node` (branch server in NodeSyncTest).
- Local test credentials (git-ignored):
  - `.local-secrets/dev-owner.txt`: owner/cashier, TOTP, PIN;
  - `.local-secrets/dev-handheld.txt`: storekeeper `yaw` and warehouse manager `kojo` PINs.
- Browser devices:
  - till on `localhost:5173` ("Demo till (live test)");
  - handheld on `handheld.localhost:5173` ("Scanner (demo)") — a separate origin, so it has separate IndexedDB.
- Dev shop `/shop/demo-shop`. HQ warehouse uses bins.
- Processes (`.claude/launch.json`): `server` (8000), `pos` (5173), `reverb` (8080), `relay`. After `git pull`, run `migrate` and `db:seed`.
- PHP is Herd's. Use script files with `php artisan tinker <file>`.
  - PowerShell expands `${…}` inside double-quoted strings, which corrupted TS template literals once; use the Edit/Write tools for code.
  - Bash heredocs with `{{` or quotes were truncated.
- Filament 5:
  - closures name the value `$state`;
  - infolist entries use `belowContent()` (there is no `description()`);
  - use `Hidden::make()` for hidden form values (`->hidden()` drops them);
  - in tests, `flushSession()` before switching users.
- Browser pane: typing fails under mobile emulation; test the handheld at desktop size.

## Next — Phase 6 (Sales B2B + CRM + loyalty/promotions)
- Customers (profiles, credit accounts and limits, consent), quotations → sales orders → delivery notes → invoices, statements, aged receivables.
- Promotions (BOGO, mix & match, thresholds, coupons), loyalty points and gift cards on the till.
- Customer sync (field-level rules with a conflict log).
- Done-when: a wholesale order taken to an invoice to a statement.
- Owner questions to batch at the start: credit-limit policy (block or warn), loyalty earn/burn rates, and whether customer data syncs to every branch.

## Blockers
- Merge PR `phase-5-purchasing-wms` → `main` (asked in chat).
