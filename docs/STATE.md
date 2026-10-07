# State (resume file)

**Phase:** 9 — Industry modules wave 1 · **Status:** 9b (Pharmacy) done on branch `phase-9b-pharmacy` (PR to `main`); next 9c Restaurant
**Updated:** 2026-10-17 · Repo: https://github.com/washeeb/Complete-ERP-POS (private) · Phases 1–8, licensing and 9a merged (PRs #1–#13)

## Phase 9b — done (277 Pest + 36 Vitest + 7 Playwright)
- **Server:**
  - migration `2026_10_17_100001_create_pharmacy_tables` (drug fields on products, `patient_profiles`, `drug_interactions`, `prescriptions`, `sale_lines.prescription_id`);
  - `app/Domain/Pharmacy/{PharmacyService, PharmacyReports}`;
  - in `SaleService::prescription()`: the pharmacist approval and the prescription;
  - catalog `drug` and `pharmacy`; customers carry allergies and NHIS details for pharmacy staff only.
- **Back office:**
  - Product → Medicine section; Customer → Patient details;
  - Pharmacy menu: Prescriptions, Drug interactions, Controlled-drug register (print view and NHIS CSV);
  - settings `pharmacy.*`; Pharmacy module 0.1.0; roles `pharmacist` and `pharmacy_technician`.
- **Till:** `pos/src/pharmacy.ts` (warnings, generics); prescription form before payment in Sell; `till.ts` sends `prescription`, `pharmacist_approval` and `pharmacist_offline_by`.
- **Tests:** PharmacyTest (8), SetupAndModulesTest (restaurant is now the unreleased example), `pharmacy.test.ts`, e2e `pharmacy.spec.ts`.
- **Docs:** pharmacy.md, api.md, DECISIONS 9b, CHANGELOG 0.13.0.

## Phase 9a — done (269 Pest + 33 Vitest + 6 Playwright; browser-checked)
- **Owner answers (2026-10-16):** scale labels with weight in barcode; FEFO + suggested markdowns applied by a manager; pharmacist approves POM sales (9b); restaurant table service + counter with kitchen screens (9c).
- **Core:** batches in the stock ledger — `products.track_batches`, `stock_batches` (UUID v5 ids), `stock_movements.batch_id`, `stock_batch_levels`; `StockBatches` (ensure / FEFO / latest / check); `StockLedger::withBatches`, `assignOpeningBatches` (`batch_assign`, excluded from GL); batches through GRN (+ handheld), adjustments, transfers, returns, reversals; offline expired sales flagged `expired_sold`.
- **Grocery module (0.1.0, per branch):** `app/Domain/Grocery/{MarkdownService,EslRelay}`, `App\Support\Ean13`, markdown stickers (`markdowns`, sale line `markdown_id`), scale labels on the till (`pos/src/scale.ts`), Stock → Batches & expiry, Stock → Shrink, sticker print route, settings `grocery.*`.
- **Tests:** GroceryTest (5), SetupAndModulesTest updated (grocery released), `scale.test.ts`, e2e `grocery.spec.ts`.
- **Docs:** grocery.md, api.md (grocery), DECISIONS 9a, CHANGELOG 0.12.0.

## Licensing (merged, PR #12)
- Signed activation keys, expiry with grace then read-only, daily check-in, tamper detection, watermarks — see docs/licensing.md. Dev licence valid to 2027-12-31 (dev key in `.local-secrets/license-dev.json`). Before the first customer release: production key (`license:keygen`), its public key in `config/license.php`, a licence server.

## Next — Phase 9c (Restaurant)
- Owner answer: both table service and counter service, with kitchen screens.
- Scope: tables and floor plan, open tabs per table, modifiers, courses, kitchen display routing, split bills, recipes/BOM stock deduction, tips.
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
- Merge PR `phase-9b-pharmacy` → `main` (asked in chat).
