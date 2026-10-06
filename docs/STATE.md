# State (resume file)

**Phase:** 7 — Finance & accounting · **Status:** 7b done on branch `phase-7b-banking-tax` (PR to `main`); Phase 7 complete; next is Phase 8 (HR & payroll + attendance)
**Updated:** 2026-10-13 · Repo: https://github.com/washeeb/Complete-ERP-POS (private) · Phases 1–7a merged (PRs #1–#9)

## Phase 7b — done (248 Pest + 30 Vitest + 4 Playwright; browser-checked)
- **Owner answers (2026-10-13):** CSV statements now, bank APIs later; straight-line monthly depreciation; budgets branch × account × month; expense claims + petty cash float (branch manager ≤ GHS 500, finance above).
- **Domain:** `app/Domain/Banking/{BankingService,StatementCsv}`, `app/Domain/Expenses/ExpenseService`, `app/Domain/Assets/AssetService`, `app/Domain/Budgets/BudgetService`; AutoPoster kinds money_transfers, expense_claims, depreciation (calculated each run for ended months), asset_disposals; FinancialReports `budgetVsActual`, `vatReturn`.
- **DB:** bank_accounts, bank_statement_imports/lines, bank_matches, bank_matched_lines, money_transfers, expense_claims, asset_classes, fixed_assets, asset_depreciation, budget_amounts; chart purposes backfilled by migration (`ChartTemplate::ensure`).
- **Back office (Finance):** Bank & cash (import, reconcile page, move money), Money moved, Expense claims (receipt photos via `/expense-receipt/{claim}`), Fixed assets (+ kinds of asset), Budgets grid, Reports → Budget vs actual and VAT return.
- **Tests:** BankingExpensesAssetsTest (5), BankingExpensesAssetsScreensTest (4); phpunit memory_limit 512M.
- **Docs:** finance.md (banking, expenses, assets, budgets, VAT), DECISIONS 7b, CHANGELOG 0.9.0.

## Phase 7a — done (see CHANGELOG 0.8.0, docs/finance.md)
- GL, automatic posting (exactly once via gl_postings), journals, month-end lock (GM reopens), supplier bills (3-way match) and payments, reports and the ledger check (`erp:gl-check`).

## Next — Phase 8 (HR & payroll + attendance)
- Read the HR & Payroll section (§6.10) of `ERP-POS-MASTER-PROMPT.md`; done-when: a payroll run with payslips on a seeded (accountant-verifiable) Ghana profile (SSNIT tiers, PAYE).
- Ask the owner first (batched): pay frequency and pay day; allowances and deductions in use; attendance source (POS clock-in, mobile with geofence); leave types and entitlements.
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
- Merge PR `phase-7b-banking-tax` → `main` (asked in chat).
