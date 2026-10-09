# Going live (Phase 14)

Owner decisions (2026-10-30): migration from Excel/CSV, QuickBooks, another till system, or a fresh start; training material (cashier quick card, role guides, practice company, video scripts); three cut-over styles (parallel run — default, one day, one till first) and three pilot styles (one shop with support on call — default, several shops, internal dry run). Lay-person instructions: installation guide chapter 17.

## Data migration

- `App\Domain\Imports\ImportSources`: per source (`quickbooks`, `other_pos`; `excel` = our templates) and import type, the old system's headings for each of our columns (`suggest()` fills the "match columns" step) and row tidying (`prepare()`: first + last name → name; SKU from the name when missing, the same way in products/prices/stock files; QuickBooks `Category:Item` sub-items and `Type` → track stock; the default price list for single-price exports).
- New importers (`app/Domain/Imports/Importers`): `accounts` (chart; QuickBooks types; next free code by type range), `opening_balances` (one `JournalService::opening` journal built in `Importer::finish`; control-account lines skipped; refused when an unreversed opening journal exists), `customer_balances` (`CustomerService::openingBalance`; negative → store credit), `supplier_balances` (`SupplierPaymentService::opening`), `loyalty_points` (loyalty ledger type `opening`, once per customer, no expiry), `gift_cards` (`StoredValues::issue`, doc type `gift_card_opening` / `store_credit_opening`, card codes kept). `Lookup` finds customers (code, phone, exact name), suppliers (code, name), accounts (code, "code name", QuickBooks `Parent:Child`, name). Suppliers without a code get one from the name.
- AutoPoster: loyalty `opening` and `*_opening` stored values post against `opening_equity` (not loyalty expense or cash). Opening customer/supplier balances and opening stock already did. After everything is loaded, `opening_equity` must be zero.

## Go-live plan (`GoLiveService`, System → Go-live)

- `go_live_plans` (one per tenant): `sources`, `cutover_mode`, `pilot_mode`, `status` (planning, parallel, live, rolled_back), `go_live_on`, `parallel_until`, `pilot_branch_ids`, `steps` (hand ticks), `old_totals`, `snapshot_backup_id`, `support_email`, `pilot_report_time`, `pilot_report_sent_on`.
- `steps()` computes the checklist from the data each time; hand ticks only for devices, training, practice, first till, accounts (manual review).
- `reconciliation(post: true)` runs the AutoPoster, then compares counts and sub-ledger totals (`GlCheck::subLedgers`) with the old system's figures, and reports unposted rows, `GlCheck` problems and the `opening_equity` balance.
- `recordParallelDay()` stores the old system's day totals; ours come from posted sales (sum of `total_minor`, cash payments, receipts) in the company's time zone. Clean = within `PARALLEL_TOLERANCE_MINOR` (1.00) or explained; three consecutive clean days turn the step green.
- `snapshot()` runs `BackupService::run`; `goLive()` needs a snapshot from today and a reason when required steps are open; `rollBack()` records the decision (restore is `erp:backup:restore`, Chapter 12.3).

## Practice company (`PracticeCompany`)

A separate tenant (`is_practice`, `practice_of`), created by the owner/GM: branches and tills (via `OrgService::completeSetup`), enabled modules, settings (except `evat.`, `ai.`, `messaging.`, `channels.`, `license.`), products and prices (exported and re-imported through the importers), main-store stock at average cost, ten made-up customers. Chosen staff get logins with the same password hash, PIN hash and two-step secret, e-mail `local+practice@domain`, and their grants by role key and branch code. Guards: `FiscalService::mode()` → off; `Messenger::deliver()` → status `practice`, never sent; `WebhookDispatcher` → no deliveries; `ChannelSync` → no dispatch or pushes. Back office and till show a PRACTICE banner (`launch.practice-banner`, bootstrap `company.practice`). The Go-live page is hidden inside a practice company. `archive()` deactivates its users.

## Pilot support

- `SupportReports::report()` — from `App\Livewire\ReportProblem` (top bar, everyone) and `POST /api/v1/pos/problems` (till). Number `SUP-<node>-000001`; context = page, browser, app/server versions, practice flag, the server's last error lines (past day, shortened). E-mailed to the plan's `support_email`, else the real company's (for a practice company), else `ERP_SUPPORT_EMAIL`. `close()` needs `support.view`.
- `PilotReport` / `erp:pilot-report` (scheduled every 15 minutes; `--show`, `--now`): per pilot branch receipts, sales, cash; tills over/short; sales that arrived more than an hour late; shifts left open; the parallel-run difference; problem reports; health checks not OK; error lines. Sent once a day after the plan's time while the status is parallel or live (first 60 days).

## Training material

`docs/training/*.md` → `docs/training/pdf/*.pdf` with `npm run training` (in `pos`). `<!-- layout: card -->` gives the two-column one-sheet layout (cashier quick card).
