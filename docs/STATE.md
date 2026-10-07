# State (resume file)

**Phase:** 8 — HR & payroll + attendance · **Status:** done on branch `phase-8-hr-payroll` (PR to `main`); next is Phase 9 (industry modules wave 1)
**Updated:** 2026-10-14 · Repo: https://github.com/washeeb/Complete-ERP-POS (private) · Phases 1–7 merged (PRs #1–#10)

## Phase 8 — done (256 Pest + 30 Vitest + 5 Playwright; browser-checked)
- **Owner answers (2026-10-14):** monthly pay by bank, MoMo or cash; transport/meal allowances, overtime, loans/advances, Tier 3 and welfare; clocking on the till with PIN; Ghana statutory leave defaults.
- **Done-when met (payroll run with payslips on a seeded profile):** `HrPayrollTest` clocks a cashier in/out online and offline, takes annual and unpaid leave, gives an advance, prepares (payroll officer), approves (finance, not the preparer) and pays the March payroll; payslips print; the ledger ties (salaries payable cleared, PAYE/SSNIT/Tier 2/loans as expected). `PayrollCalculatorTest` checks PAYE bands and payslips against hand-worked figures.
- **Domain:** `app/Domain/Hr/{HrBaseline,PayrollCalculator,AttendanceService,HrService,PayrollService}`; `PosAuthService::verifyPin` shared by sign-in and clocking; AutoPoster kinds staff_loans, payroll, payroll_payments.
- **DB:** departments, employees, attendance_events (append-only), leave_types, leave_requests, employee_loans, payroll_profiles (GH-2024 seeded), payroll_runs, payslips, loan_repayments; payroll chart purposes backfilled.
- **Till:** Clock in / Clock out on the sign-in screen (`pos/src/clock.ts`), queued `clock` events offline; `POST pos/clock`; sync group `attendance` (up).
- **Back office (HR & payroll):** Employees (+ departments), Timesheets, Leave, Payroll (+ tax and SSNIT rules page), Loans & advances, My leave & payslips; prints: payslip(s), pay sheet, bank/MoMo CSV.
- **Tests:** HrPayrollTest, HrScreensTest, PayrollCalculatorTest (unit), e2e `clock.spec.ts`; `FakeTill` test helper moved to `tests/Support/FakeTill.php`.
- **Docs:** hr-payroll.md, api.md (clock), DECISIONS 8, CHANGELOG 0.10.0.

## Next — Phase 9 (industry modules wave 1: Grocery, Restaurant with KDS, Pharmacy)
- Read the industry modules section (§8) of `ERP-POS-MASTER-PROMPT.md`; done-when: each installable per branch with its tests.
- Ask the owner first (batched): which modules each branch runs; restaurant service style (table service, quick service, both) and kitchen printers/screens; pharmacy rules (prescription capture, batch/expiry, controlled drugs); grocery weighing scales and fresh-produce barcodes.
## Owner decisions pending
- Accountant check of the Ghana tax set-up (VAT/NHIL/GETFund rates and the payroll PAYE bands / SSNIT rates — Payroll → Tax and SSNIT rules) before go-live.
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
- Merge PR `phase-8-hr-payroll` → `main` (asked in chat).
