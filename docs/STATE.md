# State (resume file)

**Phase:** 7 — Finance & accounting · **Status:** 7a done on branch `phase-7-finance` (PR to `main`); next is 7b
**Updated:** 2026-10-12 · Repo: https://github.com/washeeb/Complete-ERP-POS (private) · Phases 1–6b merged (PRs #1–#8)

## Phase 7a — done (239 Pest + 30 Vitest + 4 Playwright; browser-verified)
- **Owner answers (2026-10-12):** Ghana SME chart, Jan–Dec year; accountant enters opening balances; 3-way match with 2 % / GHS 50 tolerance; monthly lock, GM reopens.
- **Done-when met (trial balance ties to sub-ledgers):**
  - `tests/Feature/FinanceTest` posts till sales, a return, a held-then-approved bill, an expense bill, a supplier payment, customer/supplier openings, a gift card, a write-off and a cash shortage, then `GlCheck` ties all five control accounts, the trial balance sums to zero and the balance sheet balances;
  - live dev data: Finance → Reports → Ledger check all zero differences; trial balance debits = credits GH₵ 9,121.97; balance sheet balanced.
- **Domain:** `app/Domain/Finance/{ChartTemplate,GeneralLedger,AutoPoster,JournalService,PeriodService,GlCheck,FinancialReports,AccountService}`, `app/Domain/Payables/{SupplierLedger,BillService,SupplierPaymentService}`.
- **DB:** accounts, fiscal_periods, journal_entries/lines (append-only, balanced at commit, open month only), gl_postings (exactly-once links), supplier_bills/lines, supplier_payments, supplier_ledger/balances, payable_allocations; tax_rates.input_recoverable.
- **Back office (Finance group, HQ only):** Reports, Journals (new, opening balances, post now, reverse), Month-end, Chart of accounts, Supplier bills, Supplier payments, Aged payables; supplier opening balance + "We owe" on suppliers.
- **Commands:** `erp:gl-post` (every 5 min), `erp:gl-check`.
- **Changed:** incoming stock without its own cost is valued at the average; GRN reversal leaves at receipt cost.
- **Docs:** finance.md, DECISIONS 7a, CHANGELOG 0.8.0.

## Next — Phase 7b (banking, expenses, tax)
- Bank and MoMo accounts with statement import (CSV) and reconciliation; cash management (till → safe → bank).
- Expenses / petty cash; budgets per branch and account; fixed assets and depreciation.
- VAT return and tax reports (VAT, NHIL, GETFund; accountant-verified); supplier credit notes / returns to supplier; multi-currency revaluation.
- Ask the owner first (batched): which banks/MoMo wallets and their statement formats; depreciation method and asset classes; budget level (branch × account, monthly?).
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
- Merge PR `phase-7-finance` → `main` (asked in chat).
