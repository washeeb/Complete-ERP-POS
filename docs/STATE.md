# State (resume file)

**Phase:** 6 — Sales B2B + CRM + loyalty/promotions · **Status:** 6b done on branch `phase-6b-promotions-loyalty` (PR to `main`); next is Phase 7
**Updated:** 2026-10-11 · Repo: https://github.com/washeeb/Complete-ERP-POS (private) · Phases 1–6a merged (PRs #1–#7)

## Phase 6b — done (231 Pest + 30 Vitest + 4 Playwright; live till demo verified)
- **Owner answers (2026-10-11):**
  - best single promotion per item, with basket offers and one coupon on top;
  - discount limits: cashier 0 %, supervisor 10 %, manager 25 %;
  - returns: 30 days with receipt, refunded to the original tender; without one, store credit + supervisor PIN;
  - gift cards / store credit expire 12 months after last use.
- **Done-when met:**
  - shared vectors `spec/vectors/promotions.json` pass in PHP (`tests/Unit/PromotionEngineTest`) and TS (`pos/src/promotions.test.ts`);
  - online sales are recomputed by the server; offline mismatches are kept and flagged (OfflineSyncTest);
  - loyalty and gift-card balances sync HQ ↔ branch (NodeSyncTest);
  - live till: Indomie 5-for-22 sale HQ-T01C-000001 accepted online (total 2200, discount 300, 22 points); stock, receivables and audit checks clean.
- **Domain:**
  - `app/Domain/Pricing/{PromotionEngine,PromotionService}`;
  - `app/Domain/Customers/{Loyalty,StoredValues}`;
  - `app/Domain/Pos/ReturnService`; SaleService runs the engine, manual discounts, new tenders and loyalty earn.
- **Back office:** Pricing & tax → Promotions; Sales → Gift cards & credit; loyalty on the customer page; discounts/returns on sales.
- **Till:** promotions + coupon + line/sale discounts (`cart.ts priceCart`), offline supervisor approval, Points/Gift card/Store credit tenders, Return (F8, `screens/ReturnScreen.tsx`), Take payment.
- **API:** catalog promotions, `GET pos/receipts`, `POST pos/returns`, `POST pos/account-payments` (docs/api.md).
- **Commands:** `erp:expire-balances` (HQ, 02:30); `erp:receivables-check` now covers points and cards.
- **Docs:** promotions-loyalty-returns.md, api.md, DECISIONS 6b, CHANGELOG 0.7.0.
- **Deferred:** selling gift cards at the till, offline returns, partial credit notes / refunds of account credit, sales reps and commissions.

## Next — Phase 7 (Finance & accounting)
Read the Phase 7 section of `ERP-POS-MASTER-PROMPT.md` first; batch the owner questions (chart of accounts, fiscal year, VAT filing) at the start.

## Owner decisions pending
- Accountant check of the Ghana tax set-up (before go-live).
- WooCommerce/Shopify: connect a real store only with the owner's keys.

## Dev environment notes
- **Test DBs:** `erp_test` (HQ) and `erp_test_node` (branch server in NodeSyncTest).
- **Local test credentials (git-ignored):**
  - `.local-secrets/dev-owner.txt`: owner email/password/TOTP secret, cashier;
  - `.local-secrets/dev-handheld.txt`: `yaw`/`kojo` PINs.
  - TOTP codes: run `php artisan tinker` with a script using `PragmaRX\Google2FA\Google2FA::getCurrentOtp()`.
- **Browser devices:** till on `localhost:5173`; handheld on `handheld.localhost:5173`; back office on `localhost:8000/admin` (the Browser pane is small — drive Filament forms with JS `input`/`change` events if clicking is awkward). Till staff tokens expire; sign out and in again on a 401.
- **Sample data:**
  - `erp:demo-catalog`;
  - `erp:demo-purchasing`;
  - `erp:demo-sales` (fictitious customers + 5 sample promotions).
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
- Merge PR `phase-6b-promotions-loyalty` → `main` (asked in chat).
