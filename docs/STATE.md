# State (resume file)

**Phase:** all phases of the master plan built; first client (a pharmacy) live since 2026-10-19 on an Ubuntu server, with releases delivered by `release:build` and the Ubuntu installer.
**Updated:** 2026-10-26 · Page speed (PR #104) checked on the client's server: Insights 2.7 s → 0.5 s, Today 1.1 s → 0.6 s. Every section below is merged to `main`.

## MFA reset and licensed users — branch `mfa-reset-user-limit`
- `AccessService::resetMfa`, `EditUser` action `resetMfa`, command `erp:mfa-reset` (`MfaResetCommand`), test `MfaResetTest`. `User::licensedCount()` used by `AccessService` (create/reactivate), `LicensePage`, `LicenseService::report`; test in `LicensingTest`.

## Suggested levels speed — merged (PR #106)
- `SuggestedLevels::allRows` adds each row's buying unit (`ReorderLevels::buyingUnits`, once per list); columns pass `$record['unit']` to `inBuyingUnit()`. Locally 353 → 56 reads with 469 suggestions.

## Page speed — merged (PR #104)
- From the client's `erp:page-timings` output: `DemandForecaster::calendar()` shared per run (1,500 products 5.1 s → 0.47 s, same results); `SalesHistory::daily` index-friendly range; `SystemHealth::unsynced()` (HQ peers' `last_pushed_seq`, test in `HealthHeartbeatTest`); GoLive plan/figures memo per render, `GoLiveService::steps($plan, $figures)`, `GlCheck::run($tenant, $subLedgers)`.

## Desktop till start-up — merged (PR #103)
- `pos/src-tauri/src/lib.rs`: window `.visible(false)` + `on_page_load` (Finished → show) + 4 s fallback; app 0.26.0. `setup/index.html`: `#opening` status, `#form` hidden until `showForm()`. `pos/index.html`: inline theme script and background. Ship the till installer (Packaging workflow, Run workflow) with the release.

## Pharmacy extras — merged (PR #101)
- **NHIS:** migration `2026_12_05_100001_nhis_claims` (`products.nhis_price_minor`, `nhis_claims`, `nhis_claim_lines`; chart `nhis_receivable` 1245, `nhis_rejected` 6720 via `ChartTemplate::ensure`). Tender `nhis` (`SalePayment::METHODS`, `AutoPoster::TENDER`), checked in `SaleService::nhis()`; till `pharmacy.ts nhisCover`, `Pay` prop `nhis`, boot `pharmacy.nhis` from setting `pharmacy.nhis_at_till`. `App\Domain\Pharmacy\NhisClaims` (prepare, submit, withdraw, settle; permission `pharmacy.nhis`), AutoPoster kind `nhis_claims`, `NhisClaimResource` (+ `LinesRelationManager`), CSV `print.nhis-claim` (`PharmacyReports::claimLines`). Products file column `nhis_price`.
- **Interactions:** `InteractionStarter::ROWS`, `PharmacyService::addStarter()` and `unchecked()`; header action on `ListDrugInteractions`.
- **Markdowns:** till catalog `grocery` block sent for every branch (scale prefixes only with Grocery); `PharmacyMarkdowns` page (extends `GroceryMarkdowns`, `$module`); markdown settings in group Stock.
- Tests: `PharmacyTest` (NHIS, claims, starter list, markdowns), `MedicineImportTest` (export columns line up), `GroceryTest`, `pos/src/pharmacy.test.ts`.

## One scroll, speed, access review, WhatsApp orders — merged (PR #100)
- **One scroll:** admin.css frame removed; `public/js/seslabs/hscroll.js` (BODY_END hook in `AdminPanelProvider`) pins a side-to-side bar for wide `.fi-ta-content-ctn` / `.fi-fo-table-repeater`.
- **Speed:** `erp:page-timings {--as=} {--deep} {--top=} {--page=}`, `erp:demo-scale {--as=} {--products=1500} {--sales=2000} {--days=60}` (not in production). `Authorizer` (all permissions in one query, branch memo, `Branch::saved` forgets), `App\Domain\Modules\EnabledModules` (scoped; `BranchModule` saved/deleted forget), `App\Support\RequestMemo` (scoped; `Tenant::saved` forgets) used by `FinanceUi::currency`, `AccountResource`, `ReorderRuleResource::page()`; `ReorderLevels::buyingUnits()` (bulk). Dev data after demo-scale: dashboard 43 queries (was 159), accounts 47 (154), reorder rules 61 (94); median 266 ms.
- **Access review:** `App\Domain\Access\AccessReview` (RULES, `build`, `risksFor`, `rows`), page `AccessReviewPage` (`/admin/access-review`, People & access, `audit.view`), view `filament/pages/access-review`. Test `AccessReviewTest`.
- **WhatsApp orders:** migration `2026_12_04_100001` (`whatsapp_orders`, number `WA`); `App\Domain\Channels\WhatsappOrders` (verify, receive, fetchMedia, fetchPending, makeOrder, decline, reply, tellReady), `WhatsappConnector` (type `whatsapp`, `ConnectorRegistry::ordersOnly`), `Api\V1\WhatsappController` (`GET|POST api/v1/whatsapp/{provider}`), `WhatsappMediaController` (`whatsapp-file/{order}/{index}`), `WhatsappOrderResource` (Online), `erp:whatsapp-media` every minute; `Gateways::whatsappText/whatsappMedia`, `Messenger::REPLY`; meta credentials + `app_secret`, generated `verify_token`; settings `whatsapp.orders`, `whatsapp.order_reply`; `OnlineOrderService::markReady` tells WhatsApp customers. Test `WhatsappOrdersTest`.

## Till extras and waybills — merged (PR #68)
- **Database:** migration `2026_11_15_100001`: `sales.comment`, `comment_on_receipt`, `walk_in_name`, `walk_in_phone`, `extras`; `waybills` (number `WB`).
- **Sales:** `App\Domain\Pos\SaleDetails::from` (validation, extras whitelist, `pos.require_details` per branch; offline → review item `details_missing`); `saleJson` returns comment (if printed) and walk-in; receipt copy and ESC/POS print them; Sale view shows them with *Save as customer*.
- **Till:** `src/calc.ts` (calculator, change breakdown), `src/screens/TillTools.tsx` (Calculator, SaleDetailsDialog, detailsMissing, detailsPayload); Sell: Details button, F1, require check; Pay: change in notes and coins, calculator.
- **Back office:** calculator in the top bar (`resources/views/filament/calculator.blade.php`).
- **Waybills:** `App\Domain\Logistics\WaybillService` (prepare, dispatch, deliver, cancel), `WaybillResource` (list, create, view), `print.waybill` with QR (`App\Support\Qr`), `print.waybill.pod`, public `waybill.confirm` (`WaybillConfirmController`). Shortcuts on transfers, customer orders, supplier returns, goods received. Sync: warehouse group; in `TestTradingReset`.
- **Tests:** `SaleDetailsWaybillTest` (3), `calc.test.ts`, `saleDetails.test.ts`. · Top search across 37 lists with details (SKU for products), product pickers show the SKU (branch `global-search`) · Licence generator `/vendor` (branch `licence-generator`) · Pharmacy sale rules (PR #65) · Medicine schedule in the products file and a bulk action on the list (branch `medicine-import`) · Repo private


## Licence generator — merged (PR #66)
- **Database:** migration `2026_11_14_100001`: `vendor_customers`, `vendor_plans`, `vendor_addons`, `vendor_invoices`, `vendor_payments`, `vendor_reminders`; `vendor_licenses` + customer_id, plan_id, addons, kind, site, starts_on, expires_on, price, replaces, request_code, notes, revoked_by, last_checkin_at (dates backfilled from payloads).
- **Rules:** `VendorLicensing` (`allowed`, `modules`, `package`, `issue`, `renew`, `revoke`, `health`), `VendorBilling` (`invoice`, `invoiceForLicense`, `pay`, `void`, `figures`); `LicenseIssuer` accepts an installation for renewals; `CheckinServer` records last seen.
- **Panel:** `VendorPanelProvider` (`/vendor`, registered only with `LICENSE_SERVER=true`, MFA required): Dashboard (LicenceFigures, LicenceAttention), Licences (list, issue, view: renew / change / e-mail / certificate / revoke), Customers, Plans, Add-ons, Invoices (payments, print, void), Check-ins. Prints in `routes/vendor.php` + `VendorPrintController` (certificate, invoice, CSV).
- **Config:** `license.server.url`, `staff`, `payment_details` (`LICENSE_SERVER_URL`, `LICENSE_VENDOR_STAFF`, `LICENSE_PAYMENT_DETAILS`). Command `vendor:license-reminders` 08:00.
- **Builds:** `release:build` removes the panel, screens, prints and routes. `phpunit.xml` sets `LICENSE_SERVER=true` so the panel is tested.
- **Tests:** `VendorLicensingTest` (7); LicensingTest checks the build leaves the generator out.

## Pharmacy sale rules — merged (PR #65)
- **Database:** migration `2026_11_13_100001`: `branches.settings` (jsonb, branch overrides), `pharmacy_signoffs` (one per sale).
- **Settings:** `pharmacy.pom_rule`, `pharmacy.controlled_rule` (approve | details | patient | free), `pharmacy.controlled_always_strict`, `pharmacy.max_qty_without_pharmacist`, `pharmacy.major_warning_needs_pharmacist`, `pharmacy.after_sale_check` (signoff | summary | none), all `'branch' => true`. `Settings::forBranch`, `branchKeys`, `valid`; `OrgService::updateBranch` takes `settings`.
- **Rules:** `App\Domain\Pharmacy\PharmacyRules` (`forBranch`, `saleLevel`, `unapproved`), `PharmacySignoffs::sign`; `SaleService::prescription` follows the level.
- **Till:** catalogue `pharmacy.rules`; `pharmacy.ts saleLevel`; the prescription form asks only what the level needs; pharmacist optional below `approve`.
- **Screens:** Pharmacy → Sales to sign off (`MedicineSignoffs`, filters, bulk sign off, CSV); branch form *Pharmacy rules for this branch*. Command `erp:pharmacy-signoffs` 19:00.
- **Sync / reset:** `pharmacy_signoff` in the reviews group; in `TestTradingReset`.
- **Tests:** PharmacyTest (+3), `pharmacy.test.ts`.

## Minimum stock — merged (PR #63)
- **Database:** migration `2026_11_12_100001`: `reorder_rules.source` (own | smart | default), `reorder_level_suggestions` (weekly working list).
- **Rules:** `App\Domain\Purchasing\ReorderLevels` (`forWarehouse`, `buyingFactors`, `buyingUnit`, `low`); `SmartReorderLevels` (`recalculate`, `accept`) on `DemandForecaster`; `ReorderService::saveRule($source)`, `useDefault`, `store`; suggestions cover every product, at the minimum counts.
- **Settings:** `stock.default_min_qty` (5), `stock.default_exclude_slow_movers` (off), `stock.smart_levels` (suggest | auto | off), `stock.low_stock_alerts` (on).
- **Command:** `erp:reorder-levels --smart` (Mondays 05:00), `--alerts` (daily 07:00, notifies `purchasing.manage`).
- **Screens:** Reorder levels (own figure in any unit, every store, *Use the default*, source column), Suggested levels page, *What to order* shows the source, dashboard *Low stock*. Products import/export `min_qty` / `max_qty` (buying unit). Till: catalogue `min_qty`, `isLow` → amber *Low* (`.stock-low`).
- **Tests:** `ReorderLevelsTest` (5), `pos/src/stock.test.ts`.
- **Docs:** manual 14.5 (expiry moved to 14.6); purchasing-and-warehouse; CHANGELOG; DECISIONS.

## Expiry & returns — merged (PR #62)
- **Database:** migration `2026_11_11_100001`: `stock_adjustments.kind` (`adjustment` | `disposal`), `disposal_method`, `disposed_on`, `witness_name`, `certificate_path`; `suppliers.return_days_before_expiry`; `supplier_returns` + `supplier_return_lines` (number `RTS`, `refund_account_id`); account `1350 supplier_returns` via `ChartTemplate::ensure`.
- **Rules:** `App\Domain\Inventory\ExpiryService`: `batches()` (filters), `dispose()`, `prepareReturn()`, `postReturn()` (movement `supplier_return`, strict; supplier-ledger `debit_note` for credit note / replacement), `settle()` (refund needs `payables.pay`), `cancelReturn()`, `requireApprover()`. `StockAdjustmentService::post` always needs a different approver for disposals. `StockBatches::fefo` honours `stock.block_sale_days_before_expiry`.
- **Accounts:** AutoPoster `STOCK_COUNTER['supplier_return']`, kinds `supplier_returns` and `supplier_refunds`.
- **Settings:** `stock.expiry_alert_days` (90), `stock.block_sale_days_before_expiry` (0), `stock.disposal_evidence` (`method_witness`).
- **Access:** `stock.expiry` (Core, L2) for general/warehouse/branch managers, storekeeper, pharmacist, pharmacy technician, superintendent (who also gets `stock.adjust.approve` on new installs; existing companies add it by hand). Notifications `stock_disposal.prepared`, `supplier_return.prepared`.
- **Screens:** `ExpiryReturns` page (array table, bulk Dispose / Return to supplier / Mark down, disposal register), `SupplierReturnResource` (list, view: Approve and send, settlement, cancel, return note), disposal section and print on stock adjustments, supplier return window field, pharmacy dashboard stats. Prints `print.supplier-return`, `print.disposal`, `print.disposal-register`; `disposal.certificate`. Batches & expiry's one-person write-off removed (link instead).
- **Sync / reset:** `supplier_return` in the warehouse group with its lines; included in `TestTradingReset` (`RTS` restarts).
- **Tests:** `ExpiryReturnsTest` (7).
- **Docs:** manual 14.5; pharmacy guide; storekeeper guide; CHANGELOG; DECISIONS.

## Separate books — merged (PR #38)
- **Database:** migration `2026_11_05_100001`:
  - `legal_entities.is_primary`, `address` and `is_active` (the earliest entity becomes primary);
  - `journal_lines.legal_entity_id`, backfilled from the branch, else the primary;
  - account `1250 intercompany` (control) via `ChartTemplate::ensure`.
- **Ledger:** `GeneralLedger::post` stamps each line's entity (`entityOf`) and adds pairwise intercompany lines (`balanceEntities`). `JournalService` lets a line without a branch name its `legal_entity_id`, and reversals keep the entity.
- **Org:** `OrgService::saveLegalEntity` (exactly one primary; no switching off while in use); `LegalEntityResource` (Company → Legal entities). `IndustryService` keeps the branches' `legal_entity_id` in line with the industry's (`syncEntity`); the industry form calls it *Books (legal entity)*. `LegalEntity::primary()`.
- **Default:** setting `finance.separate_books` (on). `IndustryService::ensureCompany` runs on industry creation and `applyBooksChoice` when the setting changes; migration `2026_11_05_100002` keeps existing tenants off.
- **Stock:** AutoPoster credits the sender's in-transit on `transfer_in`.
- **Reports:** an `$entity` parameter on TB, P&L, BS, ledger, profit by industry and VAT (`entityBranches`). The page has a *Books* filter, shown when there is more than one active entity; the VAT return defaults to the primary. `GlCheck` checks per-entity balance and intercompany agreement.
- **E-VAT:** the `X-Taxpayer-TIN` header is the selling company's TIN.
- **Tests:** `SeparateBooksTest` (5).
- **Docs:** manual 8.0 *Separate books*; accountant guide; CHANGELOG 0.34.0; DECISIONS.

## Industries part C — merged (PR #37)
- **Database:** migration `2026_11_04_100001`: `journal_lines.industry_id`, backfilled from branches with the append-only trigger lifted for that one statement.
- **Ledger:** `GeneralLedger::post` stamps each line with its branch's industry (or an explicit `industry_id`). `JournalService::reverse` keeps the industry and can reverse `allocation` journals.
- **Reports:** `FinancialReports` methods take `?string $industry` (an id, or `FinancialReports::COMPANY`) and add `profitByIndustry()`. The page has a *Profit by industry* report and an Industry filter (defaulting to `IndustryContext`); the balance-sheet note covers one industry.
- **Sharing:** `App\Domain\Finance\HeadOfficeShare` (proposal, post), setting `finance.share_head_office_costs` (off), and the page action *Share head office costs* (`finance.journal`).
- **Tests:** `IndustryBooksTest` (3).
- **Docs:** manual 8.0 (Accounts by industry); accountant guide; CHANGELOG 0.33.0; DECISIONS.

## Industries part B — merged (PR #36)
- **Database:** migration `2026_11_03_100001`: `stock_requests` + `stock_request_lines` (number `SRQ`); `stock_transfers.stock_request_id`; `stock_transfer_lines.to_product_id`.
- **Rules:** `App\Domain\Inventory\StockRequestService` (request, approve, reject, cancel, supply).
  - Requests between industries need two approvals: the asking side (not the requester), then the supplier. `StockRequest::waitingFor()` returns requester, supplier, supply or null.
  - `supply` creates and dispatches the transfer; `TransferService::receive` closes the request.
- **TransferService:**
  - refuses transfers between industries without a request, and products from another industry's catalogue (`assertCatalogue`);
  - mapped lines land as `to_product_id`, base unit for base unit, at cost, with batches recreated.
- **Access:** `stock.request`, `stock.request.approve` (Core 0.16.0, RoleCatalog).
- **Sync:** `stock_request` in the `warehouse` group, with its lines in `CHILDREN`.
- **Screens:** `StockRequestResource` (list, create, view with Approve / Send / Reject / Cancel; the badge counts what waits on you).
- **Tests:** `StockRequestTest` (4).
- **Docs:** manual 8.0; storekeeper and manager guides; CHANGELOG 0.32.0; DECISIONS.

## Industries part A — merged (PR #35)
- **Database:** migration `2026_11_02_100001`:
  - `industries` (code, name, module_key, legal_entity_id for part C, sort, is_active);
  - `branches.industry_id` (null = company-level), `products.industry_id` and `price_lists.industry_id` (null = shared);
  - backfill: one industry per enabled industry module, assigned to its branches.
- **Rules:**
  - `IndustryService` (save, assign, ensureFor). `ModuleService::setStatus` calls `ensureFor` on enable: one industry per branch; the industry is auto-created on first use.
  - `ModuleManifest::definesIndustry()` and `addon`: Distribution is an add-on.
  - `OrgService::updateBranch` takes `industry_id`.
- **Access:**
  - `ScopeType::Industry`: `Authorizer::scopePath` includes `industry:X`; `branchIds` expands industry grants.
  - `AccessService::resolveScope`; the grant form; `RoleAssignment::scopeLabel`.
- **Switcher:**
  - `App\Support\IndustryContext` (session `erp.industry`, options limited to what the person reaches);
  - `Livewire\IndustrySwitcher` on the GLOBAL_SEARCH_BEFORE hook;
  - `Erp::branchIds` intersects with the chosen industry.
- **Catalogue:**
  - ProductResource and PriceListResource: an Industry field (default = chosen industry), a column, and a filter on the chosen industry plus shared;
  - CatalogService saves `industry_id`;
  - PosController `catalog()` and `lists()` filter by the branch's industry (plus shared); `catalogVersion(industry)`.
- **Screens:** IndustryResource (Company → Industries); BranchResource Industry field and column; dashboard widgets IndustryOverview (all industries) and IndustryHighlights (chosen industry: pharmacy, grocery).
- **Sync:** `industry` in the `org` group.
- **Licensed modules only:** `ModuleService::licensed()` (wizard, Modules screen, industry module picker); `Industry::licensed()` / `inUse()` (pickers / switcher and dashboards); `enabledAnywhere` requires the licence. The pentest copy has one branch per industry.
- **Tests:** `IndustriesTest` (5). SetupAndModulesTest is updated: Food Court is restaurant only, and the module-scope test uses grocery.
- **Docs:** manual 8.0 (Industries), 7 (Step 4b); CHANGELOG 0.31.0; DECISIONS.

## Cash control — merged (PR #34)
- **Owner answers (2026-10-12):** industries (one industry per branch; head office may be company-level; books chosen per installation; fully separate catalogues; cross-industry transfers at cost with approval from both sides; industries come after the cash chain). Cash: vault = cash tray per till per shift; blind counts at every step; the Cashier does not sell; differences go to over/short or are charged to staff; the Income Controller records deposits.
- **Database:** migration `2026_11_01_100001`:
  - tables `shift_templates`, `vaults`, `vault_sessions`, `cash_handovers`, `cash_variances`, `cash_movements` (append-only trigger);
  - `shifts.vault_session_id` and `counted_denoms`;
  - the `cashier` role renamed `teller`;
  - Morning/Afternoon backfilled; accounts `cash_cashier` (1115) and `cash_differences` (1125).
- **Server:**
  - `app/Domain/Pos/CashControl` (issue/cancel, attachShift, tellerCounted, receiveVault with one blind recount, handOver, receiveHandover, decide, deposit, ensureVaults, DENOMINATIONS);
  - ShiftService open/close and PosSyncService replay carry `vault_session_id` and `denoms`; PosController bootstrap `cash`;
  - AutoPoster kind `cash`; `shifts` excludes vault shifts;
  - BankingService::transfer takes a permission; EmployeeLoan kind `shortage`; TenantBaseline seeds the shifts;
  - Settings `cash.vaults`; Replication groups `org` (+shift_template, vault) and new `cash`; DocumentNumbers `CH`.
- **Screens:** Filament pages CashDesk, IncomeControl and CashSetup (nav group *Cash*); `Filament/Support/CashCountForm`.
- **Till:** OpenShift (vault choice and float confirmation) and CloseShift (denomination grid; blind result on a vault); `till.ts`, `api.ts`, `types.ts` (CashInfo), `db.ts` LocalShift `vault`.
- **Roles and permissions:** RoleCatalog teller/cashier/income_controller; Core 0.15.0 adds `cash.view`, `cash.setup`, `cash.vault.issue`, `cash.vault.receive`, `cash.handover`, `cash.control`, `cash.variance.approve`, `cash.deposit`.
- **Tests:**
  - `CashControlTest` (4): the full chain with books, the staff charge, cancel and simple mode, the screens;
  - tests now use the `teller` role key;
  - e2e `cash-vault.spec.ts` (with axe).
- **Docs:** manual 7 (Step 14), 17.7, 18.2; training 01 (Teller quick card) and new 07; user-manual cashier; pentest environment; CHANGELOG 0.30.0; DECISIONS.

## Ubuntu server: Caddy + HTTPS — merged (PR #30)
- **Script:** `installer/ubuntu/install.sh` (10 steps).
  - Caddy from its apt repository; nginx and certbot retired.
  - `/etc/caddy/Caddyfile` is generated: sites are `--domain` (Let's Encrypt) or `<ip>, <hostname>` with `tls internal` plus an http block serving `/ca.crt`; CI uses localhost with `default_bind 127.0.0.1`.
  - `php_fastcgi` goes to the unix socket; the `caddy` user is in `www-data`.
  - Root certificate copied to `/usr/local/share/ca-certificates/seslabs-<host>.crt` and `<dir>/seslabs-certificate.crt`.
  - `seslabs-reverb` runs on 127.0.0.1:8080.
  - `ufw` allows SSH (port from `sshd -T`), 80 and 443.
  - Every run sets `APP_URL=https://…`, `SESSION_SECURE_COOKIE` and the `REVERB_*` client and publish values, and creates the Reverb keys once.
  - The script requires systemd.
- **CI** (`packaging.yml` ubuntu-script): installs over an nginx site and checks nginx is retired; https login; `.env` gives 404; the `/pos/` deep link; Reverb on loopback; an update run.
- **Docs:** manual 5.0, 5.4, 5.6–5.9, 3, 4.9, 9.2–9.3, 12, 18.4; launch checklist 2; CHANGELOG 0.29.0; DECISIONS.
- **Not changed:** Docker (nginx in the container; HTTPS from the host's proxy).
- **Company contacts (same branch):** `config/brand.php` `website`, `support_phone`, `support_email` (SyncLytics defaults unless `BRAND_VENDOR`); `erp.support_email` defaults to info@synclyticsenterprisesystems.com; `Branding::vendorContact()` used in licence/module messages; Licence page contact line; manual About/6/13/18. Client pack (git-ignored `client-pack/`, generator in the session scratchpad) carries address, phones, hotline, e-mail, website; registration no. and TIN still blank.

## Windows server: Caddy + HTTPS — merged (PR #29)
- **Installer:** `installer/windows/install.ps1`.
  - New step 4/10, Caddy: `-CaddyVersion` (2.10.0, SHA-512 verified), `-Domain`, `-Email`, `-Workers` (4). It writes `C:\SESLabs\Caddyfile` and `php-worker.cmd`.
  - Tasks: `<Brand> Web` (Caddy), `<Brand> PHP 1..N`, Scheduler, Live (Reverb on 127.0.0.1:8080).
  - Trusts its local certificate authority on the server and copies it to `C:\SESLabs\SESLabs-certificate.crt`.
  - Firewall allows 80 and 443 only.
  - Every run sets `APP_URL=https://…`, `SESSION_SECURE_COOKIE`, `REVERB_*` (client: site:443 https; publish: 127.0.0.1:8080 http) and creates the Reverb keys.
- **Setup wizard:** `server-setup.iss` has a "Reached from the internet?" page (`InternetArgs`). Uninstall removes the tasks, processes, the trusted certificate authority and the firewall rule.
- **Server:** `config/broadcasting.php` `reverb.options` reads `REVERB_PUBLISH_*` (falling back to `REVERB_*`); the new `reverb.client` is what the till bootstrap and the storefront hand out. Test in `ShopifyAndLiveTest`.
- **CI** (`packaging.yml` windows-installer): checks over https://localhost; `.env` gives 404; the `/pos/` deep link; 8010 closed and 8080 loopback only; an update run.
- **Docs:** manual 4.0/4.6/4.8/4.9 (new: trusting the server), 1, 2, 6, 7, 9, 13, 18.4; launch checklist 2; the pentest scope; CHANGELOG 0.28.0; DECISIONS.
- Ubuntu followed in the next branch (above).

## Penetration test pack — merged (PR #28)
- **Owner answers:** test machine at the SyncLytics office; grey box; Ghana-based and international firms; certified testers.
- **Docs:** `docs/pentest/`
  - `README` (the owner's steps);
  - `01-scope-and-rules` and `02-request-for-quote` (sent to firms; PDFs in `pdf/` via `npm run pentest`);
  - `03-test-environment` (building the isolated machine);
  - `04-findings-handling` (severity deadlines; Critical and High block the pilot; contract clauses).
- **Server:** `app/Domain/Launch/PenTestEnvironment` and `erp:pentest-setup --license=<key|file> [--out=]`.
  - Refuses if any company exists; one transaction.
  - Creates two companies (grocery, restaurant, pharmacy and services modules; HQ plus Branch A), one sign-in per role in `PenTestEnvironment::PEOPLE` with PINs for till roles, and demo data.
  - Registers a pending till device and two API clients (wide, and `products:read` only).
  - Writes secrets to `storage/app/private/pentest-accounts.txt` (0600).
- **Tests:** `PenTestEnvironmentTest` (refusals, a bad key leaves it empty, the file contents).
- **Also:**
  - launch checklist line 21;
  - manual 17.9 and 18.5;
  - `security-review.md` follow-up;
  - `training.mjs` takes a folder and pattern;
  - CHANGELOG 0.27.0; DECISIONS.
- The Windows `php artisan serve` / plain-http item was fixed before the test (Windows HTTPS, above).

## Product name and branding — merged (PR #27)
- **Owner answers:** product name **SES Labs** (no company name anywhere visible); licence-controlled white-label.
- **Product brand:** `branding/product/{mark,logo,logo-dark}.svg` + `brand.json` (traced from `original/`) → `npm run brand` (pos) writes `server/public/brand/*`, `server/public/favicon.ico`, `installer/windows/app.ico`, `pos/public` icons, `pos/src-tauri/icons` + `tauri.conf.json` name; `config/brand.php` (`BRAND_NAME`, `BRAND_VENDOR`, `BRAND_WEBSITE`); publisher/licensor = SyncLytics (`BRAND_VENDOR`, `brand.json` `company`: licence messages, installer and till Publisher/copyright, guide cover); product name SES Labs everywhere else (code, installers `server-setup.iss`/`-Brand`/`SLUG`, Docker, desktop till, guides → `SES-Labs-Installation-Guide.pdf`); webhook headers `X-Webhook-*`.
- **Client brand:** migration `2026_10_31_100001` (`brand_assets`; `tenants.brand_color`, `brand_receipt_logo`); `app/Domain/Branding/Branding`; `BrandAssetController` (`/brand/{slug}/{kind}`); `Filament/Support/PanelBrand`; page Company → Branding (`BrandingPage`); setup wizard "Your brand" step; prints, PDF exports, online shop; till bootstrap `brand`, `pos/src/brand.ts` (cache, accent, dithered ESC/POS logo), Receipt/Sell; practice copies; replication `brand_asset`.
- **Tests:** BrandingTest (5); `brand.test.ts` (3); e2e receipt logo printed as raster + axe with branding. Totals: 358 Pest, 62 Vitest, 18 Playwright. Sample client logo `branding/samples/demo-client-logo.svg` (made up) used in the guide screenshots.
- **Docs:** `branding/README.md`; manual 7 (Your brand), 18 (env); CHANGELOG 0.26.0; DECISIONS.

## Phase 14 — done (merged, PR #26)
- **Owner answers:** migration from Excel/CSV, QuickBooks, another till system, or fresh (all); training: quick card, role guides, practice mode, video scripts (all); cut-over parallel (default) / one day / one till first; pilot one shop (default) / several / internal dry run.
- **Server:** migration `2026_10_30_100001` (`tenants.is_practice`, `practice_of`; `go_live_plans`, `parallel_days`, `support_reports`); `app/Domain/Imports/ImportSources` + importers `Accounts`, `OpeningBalances`, `CustomerBalances`, `SupplierBalances`, `LoyaltyPoints`, `GiftCards` (+ `Lookup`; `Importer::finish` hook; suppliers without codes); AutoPoster posts loyalty `opening` and `*_opening` stored values to opening equity; `app/Domain/Launch/{GoLiveService, PracticeCompany, SupportReports, PilotReport}`; practice guards in FiscalService, Messenger (status `practice`), WebhookDispatcher, ChannelSync; `erp:pilot-report` (every 15 min); `config('erp.version')`, `ERP_SUPPORT_EMAIL`; till API `POST pos/problems`, bootstrap `company.practice`.
- **Back office:** System → Go-live (plan, checklist, figures check, parallel run, practice company, pilot report, go-live backup, go live / roll back), System → Problem reports; "Report a problem" (top bar) for everyone; PRACTICE banner; Import data "Coming from" + "Balances as of".
- **Till:** PRACTICE banner; "Report a problem" (bottom of the screen) with the screen and the till's last errors (`pos/src/problems.ts`).
- **Permissions:** `golive.manage` (Owner, General Manager), `support.view` (technical) — Core 0.14.0.
- **Tests:** LaunchTest (5: QuickBooks + other-POS migration tying to zero, go-live flow with parallel run/pilot report/rollback, practice company copy + guards, problem reports back office/till, screens); `problems.test.ts`; e2e practice + report a problem (axe). Totals: 353 Pest, 59 Vitest, 18 Playwright.
- **Docs:** manual chapter 17 Going live (new; reference → 18), 14.2 imports, 18 env/commands/checklist; `docs/training/*` + PDFs (`npm run training`); go-live.md; launch-checklist.md; CHANGELOG 0.25.0; DECISIONS 14.

## Phase 13 — done (merged, PR #25)
- **Owner answers:** backups to USB/folder, S3-compatible, Google Drive, SFTP; Windows installer + Docker + Ubuntu script; desktop till now; all three E-VAT modes selectable.
- **Server:** migration `2026_10_29_100001` (`backup_destinations`, `backup_runs`, `fiscal_submissions`); `app/Domain/Backup/{BackupCrypto, Destinations, BackupService}`, `config/backup.php`, commands `erp:backup` (+ `--verify`), `erp:backup:restore`, `erp:backup:key`; `app/Domain/Fiscal/{FiscalAdapter, GraEvatAdapter, FiscalService, FiscalRelay}`, `config/fiscal.php`, `erp:evat-batch`, settings `evat.mode`, `evat.daily_time`, receipt `fiscal`; `SystemHealth` + `erp:health` (scheduler heartbeat every minute, relay heartbeat); `SecurityHeaders` middleware; `erp:load-test`; phpseclib.
- **Back office:** System → Backups, Health; Finance → GRA E-VAT.
- **Installers:** `installer/windows/{install.ps1, synclytics.iss}`, `installer/ubuntu/install.sh`, `installer/docker/*` (+ `.dockerignore`); CI workflow `packaging.yml` builds and smoke-tests all of them and the desktop till; main CI installs PostgreSQL 17 client tools.
- **Desktop till:** `pos/src-tauri` (Tauri 2: printer RAW/9100, drawer, serial scale, set-up page, navigation lock); `pos/src/{escpos.ts, hardware.ts}`; Receipt silent print + set-up; Sell "Weigh".
- **Tests:** HardeningTest (6, real pg_dump backup); `escpos.test.ts`; e2e `a11y-desktop.spec.ts` (axe WCAG 2.2 AA + desktop print); cargo test (scale parsing) in CI. Totals: 348 Pest, 57 Vitest, 17 Playwright. Rust is installed locally (rustup; `npx tauri build` in `pos`). Docker and Inno Setup are not: the Docker image and server installers are verified only in CI (packaging.yml).
- **Docs:** security-review.md, performance.md, launch-checklist.md, evat.md; manual 4.0, 5.0, 9.7, 11.5, 12.2–12.3, 13, 17; CHANGELOG 0.24.0; DECISIONS 13.

## Phase 12 — done (merged, PR #24)
- **Owner answers:** SMS Hubtel/Arkesel/mNotify/webhook (client chooses); SMTP e-mail; WhatsApp built, off until keys; public API with scoped keys; QuickBooks, Xero, Sage exports.
- **Server:** migration `2026_10_28_100001` (`notifications`, `message_providers`, `message_templates`, `messages`, `saved_reports`, `api_clients`, `webhook_endpoints`, `webhook_deliveries`); `app/Domain/Messaging/*` (Messenger, Gateways, Phone, Templates, ProviderSetup, Notifier, NotificationRelay, ReceiptSender), `app/Mail/PlainMessage`; `app/Domain/Reports/*` (ReportEngine, ReportService), command `erp:reports`; `app/Domain/Api/*` (ApiClients, OpenApi, WebhookDispatcher), `EnsureApiClient` middleware, `PublicApiController` (`/api/public/v1`); `Domain/Finance/AccountingExport`; `Support/Exports/XlsxWriter`, `Domain/Imports/SheetFile`; relay runs notifications, message retries, webhooks; panel database notifications.
- **Back office:** System → Messaging, API clients; Messages → Message log, Templates; Reports → Report builder, Saved reports; Finance → Export to QuickBooks / Xero / Sage; Excel on every Export; Import data takes .xlsx with preview and column matching; dashboard stats.
- **Till:** Send receipt (SMS / WhatsApp / e-mail) on the receipt screen.
- **Permissions:** `reports.build`, `reports.schedule`, `messaging.view`, `messaging.templates`, `messaging.manage` (technical), `api.manage` (Core 0.13.0).
- **Tests:** MessagingReportsApiTest (7); full suite 342 Pest + 53 Vitest. New screens checked in a real browser (manual screenshots).
- **Docs:** integrations.md, api.md, manual chapter 16 (new; reference → 17) and 11/14 updates, CHANGELOG 0.23.0, DECISIONS 12.

## Phase 11 — done (merged, PR #23)
- **Owner answers:** no cloud AI for now (off by default, local Ollama optional); names masked + personal data local if cloud is used; copilot for managers and up; invoice scan, shop descriptions, cash-flow forecast, pricing advice.
- **Server:** migration `2026_10_27_100001` (`ai_requests`, `ai_alerts`, `ai_documents`); `app/Domain/Analytics/*` (forecast, holidays, stock health, loss prevention, customers, pricing, cash flow, alerts); `app/Domain/Ai/*` (gateway, Ollama/Claude providers, masker, copilot + tools, invoice scanner, product writer); `config/ai.php`; command `erp:insights` (04:30); settings `ai.*`, `insights.margin_alert_pct`, new `choice` setting type.
- **Back office:** Insights group — Ask your business, Insights (6 tabs), Alerts, AI usage log; Supplier bills → Scan an invoice (pre-fills the bill form); Product → Write description (AI).
- **Permissions:** `insights.view`, `insights.loss_prevention`, `ai.copilot`, `ai.usage.view` (Core 0.12.0).
- **Tests:** AiInsightsTest (8) with `tests/Support/FakeAiProvider`; full suite 335 Pest. Screens checked in a real browser (manual screenshots).
- **Docs:** ai.md, manual chapter 15 (new; reference → 16), 00/03/04 updates, CHANGELOG 0.22.0, DECISIONS 11.

## Phase 10f — done (merged, PR #22)
- **Owner answer:** actual cost. Other choices in DECISIONS 10f.
- **Server:** migrations `2026_10_25_100001` (container deposit accounts), `2026_10_26_100001` (`work_centres`, `boms` + `bom_lines`, `work_orders` + `work_order_materials`, `work_order_costs`, `qc_inspections`; accounts wip/production_absorbed/production_variance; releases reserved module); models `WorkCentre`, `Bom(Line)`, `WorkOrder`, `WorkOrderMaterial`, `WorkOrderCost`, `QcInspection`; `app/Domain/Manufacturing/{BomService, WorkOrderService, MrpPlanner}`; StockLedger types `production_issue` (strict), `production_return`, `production_output`, `by_product`; AutoPoster kind `production`; WO/QC numbers; sync group `production`.
- **Container deposits:** setting `distribution.deposits_as_liability`; AutoPoster splits container lines to `container_deposits` and container stock to `containers_out`.
- **Back office:** Manufacturing → Work orders (actions: release, output, quality check, issue, time, return, finish, cancel, job card), Bills of materials (in use / revision / make), Production planning (MRP), Work centres; `Filament/Support/MiniTable`.
- **Permissions/roles:** `manufacturing.setup|produce|qc`; Production Manager, Machine Operator, Quality Inspector; module 0.1.0.
- **Tests:** ManufacturingTest (5), DistributionTest +1 (deposits as liability); module tests now use Logistics as the planned example; full suite 327 Pest.
- **Docs:** manufacturing.md, distribution.md (deposits), modules.md, manual 08 + 15, CHANGELOG 0.21.0, DECISIONS.

## Phase 10e — done (merged, PR #21)
- **Owner answers (2026-10-23):** fully offline phone app; credit within limit; load + daily settlement; routes/visits, targets/commission, orders for delivery, returns/empties.
- **Server:** migration `2026_10_24_100001` (warehouse van fields; product containers; `routes`, `route_stops`, `visits`, `rep_targets`, `van_settlements` + lines; releases reserved modules); models `SalesRoute`, `RouteStop`, `Visit`, `RepTarget`, `VanSettlement(Line)`; `app/Domain/Distribution/{VanService, VanSettlementService, RouteService, DistributionReports}`; `VanController` (`pos/van/*`), bootstrap `van`, sync events `visit`/`order`; SaleService containers (minus lines, deposit pay-out); rep commission on van tills.
- **Back office:** Distribution → Vans (load/unload/settle), Van settlements, Routes, Visits, Rep targets, Rep performance; Product → Returnable containers; permissions `distribution.routes`, `distribution.settle`; module 0.1.0.
- **Till:** `pos/src/distribution.ts` (deposits, route, GPS), `screens/Van.tsx` (route, empties); van chip, Route (F3), Take order, Empties returned, deposit pay-out.
- **Themes:** till picker (`pos/src/theme.ts`), back office (Filament switcher; pages fixed for dark), online shop footer.
- **Tests:** DistributionTest (5), `distribution.test.ts`, e2e `van.spec.ts`; totals 321 Pest + 53 Vitest + 15 Playwright.
- **Docs:** distribution.md, manual (08 checklist, 09 van phones + theme, 07 back-office theme, 15 F3), api.md, cashier.md, CHANGELOG 0.20.0, DECISIONS 10e + themes.

## Phase 10d — done (merged, PR #20)
- CSV/PDF export everywhere, CSV imports, stock adjustments, stock sheets — see manual chapter 14.

## Phase 10d — details
- **Server:** `Support/Exports/Exporter` (CSV/PDF, dompdf); `Filament/Support/{TableExport, ExportsPage}` (Export on every table and report page; AppServiceProvider); migration `2026_10_23_100001` (`stock_adjustments` + lines, `data_imports`); `Domain/Inventory/StockAdjustmentService`; `Domain/Imports/{CsvFile, Importer, ImportService, Importers/*}` (products, prices, customers, suppliers, opening stock, stock adjustment, stock count; `StockRows` stock sheet); `CatalogService::inputOf()/withPrice()`.
- **Back office:** System → Import data (CSV); Stock → Stock adjustments (draft/submit/post, voucher PDF); Stock on hand → Stock sheet, Update stock from CSV; stock count → count sheet, import counts; Import shortcuts on Products, Customers, Suppliers.
- **Permissions/settings:** `stock.adjust.approve` (Core 0.11.0); `stock.adjustment_approval_minor`; ADJ numbers.
- **Tests:** DataExchangeTest (8); full suite 316 Pest. Real-browser check of upload → check and of PDF/CSV downloads.
- **Docs:** manual chapter 14 (+ screenshots), CHANGELOG 0.19.0, DECISIONS 10d.

## Phase 10c — done (merged, PR #19; 308 Pest + 49 Vitest + 14 Playwright at the time)
- **Server:**
  - migration `2026_10_22_100001_create_services_tables`: `products.service_minutes`, `commission_bp`, `pack`, `licence_keys`; `employees.takes_appointments`, `commission_bp`; `service_passes`, `service_pass_uses`, `appointments`, `membership_plans`, `memberships`, `commissions`, `licence_keys`; `sale_lines.staff_id`, `service_pass_id`, `pass_item`, `appointment_id`; `payslips.commission_minor`; `invoices.membership_id`;
  - `app/Domain/Services/{PassService, AppointmentService, MembershipService, CommissionService, LicenceKeyService, ServiceSales, SmsGateway}`; `app/Domain/Sales/InvoiceService` (shared with deliveries);
  - SaleService/ReturnService hooks via `ServiceSales`; payroll earning COMMISSION; SMS relay handles appointments;
  - `ServicesController` (`pos/services/*`); catalog `services` block; sale response `licence_keys`; empty payments allowed when the total is 0;
  - commands `erp:appointment-reminders` (5 min), `erp:bill-memberships` (01:30, HQ only).
- **Back office:** Services → Staff calendar, Appointments, Prepaid passes, Memberships, Membership plans, Commissions, Licence keys; Product → Services; Employee → takes appointments, commission %; role `service_staff`; `services.*` settings; module 0.1.0.
- **Till:** `pos/src/services.ts`; `screens/Services.tsx` (staff prompt, appointments F10, passes); pass lines at 0 in `cart.ts`; receipt licence keys.
- **Till hosting:** `npm run build:server` → `server/public/pos` (git-ignored), served at `/pos/`; release builds include it.
- **Installation guide:** `docs/manual/00–14-*.md` → `SES-Labs-Installation-Guide.pdf` (`npm run manual`; screenshots `npm run manual:screens`, dev server on 8010). Update it every phase.
- **Tests:** ServicesTest (6), SetupAndModulesTest (services permissions), `services.test.ts`, e2e `services.spec.ts`.
- **Docs:** services.md, api.md, modules.md (statuses), cashier.md, DECISIONS 10c + guide, CHANGELOG 0.18.0.

## Phase 10b — done (merged, PR #18)
- Fashion: styles with a size × colour matrix, consignment, alterations, sell-through — see docs/fashion.md.

## Phase 10b — details (302 Pest + 45 Vitest + 13 Playwright at the time)
- **Server:**
  - migration `2026_10_21_100001_create_fashion_tables`: `styles`; `products.style_id`, `size`, `colour`, `consignor_id`, `consignment_bp`; `consignment_sales`; `alterations`; `sale_lines.alteration_id`;
  - `app/Domain/Fashion/{StyleService, ConsignmentService, AlterationService, FashionReports}`;
  - SaleService and ReturnService hooks (alteration lines, consignment shares);
  - `FashionController` (`pos/alterations`); catalog `fashion` block.
- **Back office:**
  - Fashion → Styles (grid, create sizes and colours, one price for all), Alterations, Sell-through & consignment (settle);
  - Product → Consignment;
  - role `tailor`; setting `fashion.alteration_sku`.
- **Till:** `pos/src/fashion.ts`; `screens/Fashion.tsx` (style picker, alterations F9); alteration lines priced at their charge in `cart.ts`.
- **Tests:** FashionTest (5), SetupAndModulesTest (fashion permissions), `fashion.test.ts`, e2e `fashion.spec.ts`.
- **Docs:** fashion.md, api.md, DECISIONS 10b, CHANGELOG 0.17.0.

## Phase 10a — done (merged, PR #17)
- Electronics: serial/IMEI numbers in the ledger, warranties, repairs, trade-ins — see docs/electronics.md.

## Phase 10a — details (297 Pest + 43 Vitest + 12 Playwright at the time)
- **Owner answers (2026-10-19):** order Electronics → Fashion → Services → Distribution → Manufacturing; manufacturing at actual cost; services with both prepaid packs and recurring invoices; distribution via a phone app with vans as warehouses.
- **Server:**
  - migration `2026_10_20_100001_create_electronics_tables`: `products.track_serials` and `warranty_months`, `serial_numbers`, `stock_movement_serials`, `serials` on sale, GRN and transfer lines, `repair_jobs` and lines, `trade_ins`, account 1180;
  - `app/Domain/Inventory/SerialRegistry` (in `StockLedger::post`);
  - `app/Domain/Electronics/{RepairService, ElectronicsSales}`;
  - serials through GRN, handheld, transfers, sales and returns;
  - `InventoryService::registerSerials`; `ElectronicsController` (`pos/serials`, `pos/repairs`).
- **Back office:**
  - Electronics → Serial numbers (history, register for stock on hand) and Repairs (progress, parts and labour, hand over);
  - Product → Serial numbers and warranty;
  - serial inputs on PO receiving and transfers;
  - role `technician`.
- **Till:**
  - `pos/src/electronics.ts`;
  - `screens/Electronics.tsx` (serial prompt, trade-in, repairs with warranty check);
  - Pay trade-in; receipt serials and warranty; handheld serial scanning.
- **Tests:** ElectronicsTest (6), SetupAndModulesTest (electronics permissions), `electronics.test.ts`, e2e `electronics.spec.ts`.
- **Docs:** electronics.md, api.md, DECISIONS 10 / 10a, CHANGELOG 0.16.0.

## Phase 9 — done (merged, PRs #13–#16)
- Grocery (batches, scale labels, markdowns), Pharmacy, Restaurant (floor, kitchen, recipes, tips) and Restaurant extras (bookings, deposits, SMS, delivery apps, waste) — see docs/grocery.md, pharmacy.md, restaurant.md.

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

## Next
- Before a second client: third-party penetration test (pack in `docs/pentest`); GRA E-VAT verified with real credentials; training videos recorded (scripts in `docs/training`).

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
- None.
