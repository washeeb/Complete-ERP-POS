# State (resume file)

**Phase:** 9 — Industry modules wave 1 · **Status:** 9d (Restaurant extras) done on branch `phase-9d-restaurant-extras` (PR to `main`); Phase 9 complete after merge — next Phase 10 per the master prompt
**Updated:** 2026-10-19 · Repo: https://github.com/washeeb/Complete-ERP-POS (private) · Phases 1–8, licensing, 9a, 9b and 9c merged (PRs #1–#15)

## Phase 9d — done (291 Pest + 40 Vitest + 11 Playwright)
- **Owner answers (2026-10-18):** bookings with an optional deposit and SMS; delivery apps by webhook and manual entry; kitchen staff log waste, a manager approves above a limit.
- **Server:**
  - migration `2026_10_19_100001_create_restaurant_extras_tables`: `reservations`, `deposit_movements`, delivery fields on `dining_orders`, accounts 1240/2330/4800 keys;
  - `app/Domain/Restaurant/{ReservationService, DeliveryOrders, WasteService, SmsRelay}`;
  - `DeliveryConnector` (bolt_food, glovo, chowdeck);
  - tenders `deposit` and `aggregator`; AutoPoster `deposits`; shift cash includes deposits;
  - `erp:reservation-reminders`; SMS in `erp:relay`.
- **Back office:** Restaurant → Bookings; delivery-app channels under Online → Sales channels; `restaurant.*` settings; module 0.2.0 with `restaurant.waste`.
- **Till:**
  - `screens/RestaurantExtras.tsx` (bookings, deposit/refund, delivery order, waste);
  - floor shows booked and late tables;
  - Pay: "Use deposit" and "Paid by {app}";
  - kitchen screen: Log waste.
- **Tests:** RestaurantExtrasTest (6), `restaurant.test.ts` (bookings on the floor), e2e `restaurant-extras.spec.ts` (2).
- **Docs:** restaurant.md (bookings, delivery, waste), api.md, DECISIONS 9d, CHANGELOG 0.15.0.

## Phase 9c — done (merged, PR #15)
- Restaurant core: floor and tables, bar tabs, modifiers and courses, kitchen screens, split bills, recipes, tips and service charge — see docs/restaurant.md.

## Phase 9c — details (285 Pest + 39 Vitest + 9 Playwright at the time)
- **Owner answers (2026-10-17):**
  - tips plus an optional service charge, paid out via payroll;
  - kitchen screens with printer fallback;
  - recipes deducted when the bill is paid;
  - extras wanted: reservations, aggregators, waste log, bar tabs (bar tabs done; the rest is 9d).
- **Server:**
  - migration `2026_10_18_100001_create_restaurant_tables`: `kitchen_stations`, `dining_tables`, `modifier_groups`/`options`, `recipe_lines`, `dining_orders`/`items`; `products.station` and `modifier_group_ids`; sale tip, bill and service-line columns; `payslips.tips_minor`; account 2260;
  - `app/Domain/Restaurant/{RestaurantSetup, DiningService, DiningSales}`;
  - SaleService hooks: service line, tip, bill check and settle, recipe stock;
  - `RestaurantController` (`pos/restaurant/*`, `kds/*`); live channel `dining`;
  - tips in AutoPoster and payroll.
- **Back office:** Restaurant menu (Bills, Floor plan, Kitchen stations, Modifiers, Tips & voids); Product → Restaurant section; settings `restaurant.*`; module 0.1.0; roles `waiter` and `kitchen_staff`.
- **Till:**
  - `pos/src/restaurant.ts`;
  - `screens/Restaurant.tsx` (floor, bill, modifier picker, void);
  - `screens/Kitchen.tsx` (kitchen display mode for `kds` devices);
  - Sell: Tables (F6), paying bills, counter choices;
  - Pay: tip and even split;
  - Receipt: order number, notes, tip, offline kitchen ticket.
- **Tests:**
  - RestaurantTest (8);
  - SetupAndModulesTest (manufacturing is now the unreleased example);
  - `restaurant.test.ts`, e2e `restaurant.spec.ts` (2).
- **Docs:** restaurant.md, api.md, DECISIONS 9c, CHANGELOG 0.14.0.

## Phase 9b — done (merged, PR #14)
- Pharmacy: drug fields, patients, pharmacist PIN approval, prescriptions, warnings, controlled-drug register, NHIS CSV — see docs/pharmacy.md.

## Phase 9b — details (277 Pest + 36 Vitest + 7 Playwright at the time)
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

## Next — Phase 10
- Industry modules wave 2: Manufacturing, Fashion, Electronics, Services & Packages, Distribution (master prompt §phases). Ask the owner's decisions in one batch before starting.
## Owner decisions pending
- Accountant check of the Ghana tax set-up (VAT/NHIL/GETFund rates and the payroll PAYE bands / SSNIT rates — Payroll → Tax and SSNIT rules) before go-live.
- WooCommerce/Shopify: connect a real store only with the owner's keys.

## Dev environment notes
- **Test DBs:** `erp_test` (HQ) and `erp_test_node` (branch server in NodeSyncTest).
- **Local test credentials (git-ignored):**
  - `.local-secrets/dev-owner.txt`: owner email/password/TOTP secret, cashier;
  - `.local-secrets/dev-handheld.txt`: `yaw`/`kojo` PINs.
  - TOTP codes: run `php artisan tinker` with a script using `PragmaRX\Google2FA\Google2FA::getCurrentOtp()`.
- **Browser devices:** till on `localhost:5173`; handheld on `handheld.localhost:5173`; back office on `localhost:8010/admin` (the Browser pane is small — drive Filament forms with JS `input`/`change` events if clicking is awkward). Till staff tokens expire; sign out and in again on a 401.
- **Sample data:**
  - `erp:demo-catalog`;
  - `erp:demo-purchasing`;
  - `erp:demo-sales` (fictitious customers + 5 sample promotions).
- **Processes** (`.claude/launch.json`): `server` (8010; 8000 belongs to another project), `pos` (5173), `reverb` (8080), `relay`. After `git pull`, run `migrate` and `db:seed`.
- **PHP and shells:**
  - PHP is Herd's; use script files with `php artisan tinker <file>`.
  - PowerShell double-quoted strings eat backticks and `${…}`, which breaks TS template literals; use the Edit/Write tools for code.
- **Filament 5:**
  - closures name the value `$state`, the search callback `$search`, and the option label `$value`;
  - entries use `belowContent()`;
  - use `Hidden::make()` for hidden values;
  - in tests, `flushSession()` before switching users.

## Blockers
- Merge PR `phase-9d-restaurant-extras` → `main` (asked in chat).
