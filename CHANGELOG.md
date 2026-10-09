# Changelog

## [0.26.0] — 2026-10-31 · Product name and branding
### Changed
- The product is now called **SES Labs** — *Engineering Solutions. Empowering Businesses.* — with the owner's SES lettermark (traced as vector in `branding/product/ses-lettermark.svg`; app icon = the full SES on a white tile) (one setting: `config/brand.php` / `BRAND_NAME`; artwork in `branding/product`). The former company name no longer appears anywhere a user, customer or integrator looks: screens, messages, licence texts, installers (`C:\SESLabs`, `SESLabsDB`, `/var/www/seslabs`, Docker image `seslabs`), the desktop till ("SES Labs Till"), guides. Webhook headers are now `X-Webhook-Event`, `X-Webhook-Delivery`, `X-Webhook-Signature` (and `X-Webhook-Timestamp`, `X-Webhook-Id` for the generic REST shop). The installation guide is `SES-Labs-Installation-Guide.pdf`.
### Added
- **Product brand** files (mark, logo, dark logo, built by `branding/product/compose.py`) and `npm run brand`, which makes every favicon, home-screen icon (incl. maskable), the Windows `.ico`, the desktop till's icons and name, and the guide-cover logo from it.
- **Client branding** (Company → Branding, and a "Your brand" step in the setup wizard): logo, dark-background logo, square icon and brand colour, shown on the back office, the sign-in page of a single-company server, tills (icon, accent, receipt logo — printed as dithered black-and-white on thermal printers, cached for offline), invoices and other prints, PDF exports and the online shop. The colour is suggested from the logo, with a readability warning; a monogram (initials on the colour) stands in until an icon is uploaded. Uploads are checked and re-encoded; SVGs are cleaned and served with a locked-down content policy. Logos sync to branch servers and are in backups. Practice companies copy them.
- **White-label**: "Powered by SES Labs" in footers and on receipts and documents, removed by a licence that names `white_label`.

## [0.25.0] — 2026-10-30 · Phase 14 Pilot and launch
### Added
- **Data migration** from Excel/CSV, **QuickBooks** and **other till systems** (Loyverse, Square, Lightspeed, Odoo…): a "Coming from" choice on Import data matches the old system's column names; SKUs and supplier codes made from names when missing; QuickBooks sub-items and types understood. New imports: chart of accounts, opening trial balance (one opening journal; control-account lines skipped), customer balances (negative = store credit), supplier balances, loyalty points, gift cards and store credit — all posted against "Opening balances (to clear)".
- **System → Go-live**: the plan (data sources; parallel run / one day / one till first; one shop / several / internal dry run), an ordered checklist that ticks itself from the data, the figures check against the old system (with the ledger tie-out), the parallel run (old system's daily totals next to ours), the go-live backup, go live and roll back.
- **Practice company** for training: a copy of branches, tills, settings, products, prices and stock with made-up customers; staff keep their password, PIN and two-step code (e-mail with "+practice"); a PRACTICE bar everywhere; nothing is sent to GRA, customers, online stores or webhooks.
- **Report a problem** in the back office and on the till (with the page, versions and recent errors), e-mailed to support; System → Problem reports. **Pilot report** e-mailed every evening during the pilot (`erp:pilot-report`).
- **Training material** (`docs/training`, `npm run training`): cashier quick card, supervisor, storekeeper, accountant and manager/owner guides, video scripts. Installation guide chapter 17 "Going live" with the rollback plan (reference is now chapter 18).

## [0.24.0] — 2026-10-29 · Phase 13 Hardening
### Added
- **Automatic encrypted backups** every night (database, settings, uploaded files; Argon2id + XChaCha20-Poly1305) to a USB/second-drive folder, S3-compatible storage, Google Drive or SFTP, with retention per destination; weekly check (decrypt, read, restore drill into a scratch database); `erp:backup`, `erp:backup:restore`, `erp:backup:key`; System → Backups.
- **GRA E-VAT** with three selectable modes (each sale as it happens with the GRA code/QR on the receipt, a daily batch, or invoices only); retries; Finance → GRA E-VAT.
- **System → Health** / `erp:health` (scheduler, relay, backups and restore check, disk, live-site settings, HTTPS, e-mail, licence, stuck queues).
- **Installers:** Windows `setup.exe` (Inno Setup + `install.ps1`: PostgreSQL 17, PHP 8.4, Composer, start-up tasks), Ubuntu `install.sh`, Docker image + compose; all built and smoke-tested in CI.
- **Desktop till (Tauri):** silent ESC/POS receipt printing (Windows printer or network 9100), cash-drawer kick after cash, scale reading on COM ports, locked to the shop's server.
- Security headers on every answer; WCAG 2.2 AA automated checks of the till (contrast fix); `erp:load-test`; security review, performance results, launch checklist.

## [0.23.0] — 2026-10-28 · Phase 12 Reporting, notifications, integrations
### Added
- **Messaging:** SMS through Hubtel, Arkesel, mNotify or any gateway (webhook) — the client chooses; WhatsApp Business (Meta Cloud API, approved templates; off until keys); e-mail through any SMTP server. Editable templates with on/off per message; message log with automatic retries; test buttons.
- **Notifications:** back-office bell for approvals waiting (purchase orders, bills, stock adjustments, deliveries, counts, van settlements, expense claims); customer messages for invoices and payments (off by default); booking and appointment messages now through the chosen provider.
- **Digital receipts** from the till by SMS, WhatsApp or e-mail.
- **Report builder:** 8 datasets, group-by and figures, periods, branch and filters, drill-down to the rows and source documents, totals; saved and **scheduled reports** (daily/weekly/monthly) by e-mail with the file, WhatsApp or the bell.
- **Excel (.xlsx)** export everywhere and Excel import with preview and column matching.
- **Dashboard** headline figures by role.
- **Public REST API** (`/api/public/v1`) with scoped keys acting as a person, signed webhooks with retries, OpenAPI 3.1 description.
- **Accounting export** for QuickBooks Online, Xero and Sage 50 / Pastel.
- Permissions `reports.build`, `reports.schedule`, `messaging.view`, `messaging.templates`, `messaging.manage`, `api.manage` (Core 0.13.0); commands `erp:reports`; installation guide Chapter 16 (reference is now 17).

## [0.22.0] — 2026-10-27 · Phase 11 Insights and AI
### Added
- **Insights** (no AI needed): demand forecast with weekday, payday and Ghana public-holiday effects, reorder quantities and min/max; stock health (dead stock, overstock, expiry risk, transfers between branches); customers (RFM segments, regulars who stopped coming, bought together, next-best offer); pricing (low margins, markdowns, promotion uplift); 8-week cash-flow forecast; loss prevention (cashier risk scores).
- **Alerts**, refreshed nightly (`erp:insights`): running out, will expire unsold, low margin / below cost, unusual till patterns, cash shortfall; dealt with / not a problem.
- **AI assistant** (off by default): local model through Ollama or Claude within a monthly budget; **Ask your business** copilot over read-only reports within each person's access (quick questions work without AI); **Scan an invoice** into the supplier bill form; **Write description (AI)** for products; AI usage log with masked prompts and cost.
- Privacy: names, phones and e-mails masked before any cloud call; personal data kept on the server (setting).
- Permissions `insights.view`, `insights.loss_prevention`, `ai.copilot`, `ai.usage.view` (Core 0.12.0); settings under System → Settings → AI; `ANTHROPIC_API_KEY` in `.env` (optional).
- Installation guide: new Chapter 15 (insights, installing Ollama, switching the assistant on, safety); reference is now Chapter 16.

## [0.21.0] — 2026-10-26 · Phase 10f Manufacturing
### Added
- **Work centres** with cost per hour (labour + overhead) and hours per day.
- **Bills of materials:** multi-level (sub-assemblies), materials with expected loss, by-products, routing of operations, quality check flag; draft → in use → replaced with revisions; loop check; estimated cost per unit.
- **Work orders at actual cost:** release (copies the BOM; optional sub-assembly orders), issue and return materials, backflush of materials and operations on output, recorded time, scrap, quality checks by a separate inspector, finish (left-over cost to production differences), cancel, job card PDF.
- **Production planning (MRP):** what to make and what to buy, level by level, from customer orders, reorder levels and open work orders; raise draft work orders; work centre load.
- Accounts 1340 Work in progress, 5500 Labour and overhead charged to production, 5600 Production cost differences; automatic posting of production costs.
- Permissions `manufacturing.setup`, `manufacturing.produce`, `manufacturing.qc`; roles Production Manager, Machine Operator, Quality Inspector; module Manufacturing 0.1.0; sync group `production`.
- **Container deposits:** setting to book them as a liability (2340 Container deposits held, containers out as asset 1330) instead of sales.

## [0.20.0] — 2026-10-24 · Phase 10e Sales & Distribution, themes
### Added
- **Vans:** a van is a warehouse with its own till (the rep's phone sells fully offline); load and unload from the depot; end-of-day **van settlement** (expected vs counted, unload, cash from the shift, approval by another person, settlement sheet PDF).
- **Routes and visits:** routes with weekdays and customers in order; on the phone **Route (F3)** with Sell / Take order / No order / Closed and GPS; visits offline-queued; Visits list with map links.
- **Orders for delivery** taken on the phone (credit-checked, on hold over the limit), offline-queued.
- **Credit sales from the van** within the customer's limit (existing on-account tender and offline checks).
- **Rep targets and commission:** monthly targets; rep commission on van sales through payroll; **Rep performance** report (sales vs target, strike rate, orders, collections, missed customers, empties held).
- **Returnable containers (empties):** deposit lines added automatically; empties handed back as minus lines; deposit paid out in cash when only empties come back.
- **Themes:** System / Light / Dark on the till (every screen), in the back office (profile menu; custom pages fixed for dark) and in the online shop.
- Permissions `distribution.routes`, `distribution.settle`; Van Sales Rep can use the till; module Sales & Distribution 0.1.0.
### Fixed
- Released modules now switch on at branches that had reserved them in the setup wizard (missed in earlier releases).

## [0.19.0] — 2026-10-23 · Phase 10d Data import & export, stock adjustments
### Added
- **Export everywhere:** every back-office list and report page has **Export → CSV (Excel) / PDF** with the current search, filters and sort (all pages). PDFs carry the company, title, filters, who printed it, page numbers and the licence reference; CSVs have a UTF-8 byte-order mark and are protected against formula injection. Every export is audited; read-only licences block exports.
- **Import data (CSV)** (System menu, and shortcuts on Products, Customers, Suppliers, Stock adjustments, Stock counts): products (create/update by SKU, barcodes, a price column per list, categories and brands created on request), prices (tiers and dated promotions), customers (with credit), suppliers, opening stock, stock adjustment lines and stock count results.
  - Template, column guide (PDF) and "current data to edit" (round trip) per kind; **Check file** runs every row through the real services and undoes everything; import all-or-nothing or skipping bad rows; error report CSV; import history; audit log.
  - Reads Excel/Sheets CSVs: comma, semicolon or tab; UTF-8 or Windows encoding; loose headings; numbers with separators; DD/MM/YYYY dates.
- **Stock adjustments** (Stock → Stock adjustments): documents with any number of lines — increase (+), decrease (−) or set to a counted quantity — per warehouse and reason, with reference; draft, submit, approve and post; approval by another manager above a value limit (setting, default GHS 500); before/change/after preview with value; adjustment voucher PDF.
- **Stock takes:** stock sheet (current stock standing, with or without system quantities, PDF or CSV) on Stock on hand; count sheets (PDF/CSV) and "Import counted quantities" on stock counts; "Update stock from a CSV file" as a set-to adjustment that skips unchanged rows.
- Permission `stock.adjust.approve`; setting `stock.adjustment_approval_minor`; ADJ document numbers.
- Installation guide: new chapter 14 (exporting, importing, stock adjustments, stock takes); php.ini upload folder.
### Fixed
- Imports and exports now write their audit rows inside a transaction (found testing in a real browser).

## [0.18.0] — 2026-10-22 · Phase 10c Services & Packages
### Added
- **Services and staff:** bookable services with a duration; staff who take appointments; commission rates per person and per product.
- **Appointments:** booking without double-booking staff, arrivals, no-shows, cancellations, changes; a staff calendar (day grid) in the back office; the appointment book at the till (F10) with check-out; SMS confirmations and reminders through your own gateway.
- **Prepaid packs:** a pack product becomes a pass for the customer; sessions used at the till at no charge (Passes, per customer); returns give sessions back or void unused packs; passes can be voided.
- **Memberships and subscriptions:** plans with a price per period (1, 3, 6 or 12 months) and sessions included per period; sign-up, pause, resume, cancel; each period invoiced to the customer's account (`erp:bill-memberships`, head office, nightly).
- **Commissions:** per sale line for the person who did it (session value for passes), reversed by returns; Commissions report; paid through payroll as the earning *Commission* (commission-only staff included).
- **Software licence keys:** loaded per product and branch, one handed out per unit sold and printed on the receipt; returned keys are never resold.
- Role **Stylist / Therapist**; permissions `services.appointments`, `services.passes`, `services.memberships`, `services.commissions`, `services.licence_keys`; `services.*` settings.
- **Installation & Setup Guide (PDF)** — `docs/manual/`, built with `npm run manual` (in `pos/`): definitions, choosing a setup, requirements, Windows and Ubuntu installation, licence, first set-up, modules, devices, branch servers, online integrations, backups, updates, troubleshooting, reference; screenshots with `npm run manual:screens`.
- **The server hands out the till app at `/pos/`** (`npm run build:server`), so a production install needs no separate web server for tills; release builds include it.
### Changed
- Customer invoices are raised by one `InvoiceService` (deliveries and memberships).
- A till sale with nothing to pay (only prepaid sessions) is accepted without payments.

## [0.17.0] — 2026-10-21 · Phase 10b Fashion & Boutique
### Added
- **Styles** with sizes and colours, season and collection:
  - one product per size × colour, created with SKU, internal EAN-13 barcode and the style price;
  - one price for all;
  - a grid of stock and sales on the style page.
- **Size × colour picker at the till**, showing stock at that till; swing-tag barcodes add the exact variant.
- **Consignment:** a product's consignor and their share, stock received at no cost, the share recorded on each sale (returns take it back), settlement raising a supplier bill.
- **Alterations** (till F9 and Fashion → Alterations): agreed charge, ready-by date, progress, paid at the till at the agreed price or handed over free.
- **Sell-through by style and season;** what consignors are owed, with Settle.
- Role **Tailor**; permissions `fashion.alterations` and `fashion.consignment`; setting `fashion.alteration_sku`; adjustment reasons for consignment in and out.
## [0.16.0] — 2026-10-20 · Phase 10a Electronics
### Added
- **Serial / IMEI numbers** on products ("Serial / IMEI numbers"), kept in the stock ledger:
  - received by number (back office and handheld), sold, returned and transferred by number;
  - offline gaps are flagged;
  - numbers can be registered for stock already on hand.
- **Warranty** months per product. Each unit sold carries its warranty end date, printed on the receipt with its number.
- **Electronics → Serial numbers:** where every unit is, who bought it, its warranty and history.
- **Repair job cards** (till Repairs F7 and Electronics → Repairs):
  - warranty detected from the serial number;
  - progress log;
  - parts and labour from the price list, free under warranty;
  - return to the supplier (RMA);
  - collect and pay at the till, or hand over with parts written off.
- **Trade-ins** at payment: a used item taken as part-payment, approved by a supervisor, into stock at the value given (account 1180 Trade-ins awaiting stock).
- Role **Repair Technician**; permissions `electronics.repairs` and `electronics.trade_in`.
## [0.15.0] — 2026-10-19 · Phase 9d Restaurant extras
### Added
- **Bookings:** guest, phone, party size, time and table, with no double booking. Booked tables show on the floor (late after 15 minutes). Seat, no-show, cancel.
- **Booking deposits** taken at the till and held as customer deposits. They are used on the bill as a tender, refunded (supervisor PIN), or kept after a no-show (manager → Other income).
- **SMS hook:** booking confirmation and a reminder before the booking, posted to your SMS gateway's address.
- **Delivery apps (Bolt Food, Glovo, Chowdeck):** orders keyed in at the till or received by signed webhook. They go straight to the kitchen and are paid by the app on hand-over (account 1240 Due from delivery apps).
- **Kitchen waste log** at the till and on the kitchen screen (cook's PIN). Dishes count as their recipe's ingredients, and a manager approves above a value limit. Shown in Stock → Shrink.
- Back office: Restaurant → Bookings.
- Settings → Restaurant: waste limit, SMS gateway address, confirmations, reminder hours, hold time.
- Permission `restaurant.waste` (Kitchen Staff and up).
## [0.14.0] — 2026-10-18 · Phase 9c Restaurant
### Added
- **Restaurant module** (per branch). Set-up:
  - kitchen stations (screen, or print tickets);
  - floor plan with tables;
  - modifier groups (free or charged choices);
  - per-dish station, choices and **recipe**.
- **Table service and bar tabs** on the till and waiter tablets (Tables, F6):
  - open a bill per table or tab name;
  - add dishes with choices, course, seat and hold;
  - send to the kitchen, call courses, mark served;
  - void (supervisor PIN once the kitchen has it);
  - move table;
  - pay all or selected items (split by guest or seat).
- **Counter service:** dishes with choices are asked at the till; paid dishes go to the kitchen as a counter order; the order number is on the receipt. Offline, the till prints the kitchen ticket.
- **Kitchen screens** (Kitchen display devices, no sign-in): tickets by station, oldest first, with timers; ready, recall and collected. Stations without a screen print each new dish.
- **Service charge** line on table and tab bills (Settings → Restaurant); **tips** at payment, with even-split help.
- **Recipes:** ingredients come off stock when the bill is paid.
- **Tips** are owed to the waiter (account 2260 Tips owed to staff) and paid out with that month's payroll as a taxable earning.
- **Back office:** Restaurant → Bills, Floor plan, Kitchen stations, Modifiers, Tips & voids.
- **Roles:** Waiter / Bartender and Kitchen Staff.
- **Permissions:** `restaurant.serve`, `restaurant.void`, `restaurant.manage`.
## [0.13.0] — 2026-10-17 · Phase 9b Pharmacy
### Added
- **Pharmacy module** (per branch).
- **Medicine details on products:** schedule (OTC / prescription only / controlled), generic name, strength, form, FDA registration number, active ingredients. Medicines must track batches and expiry.
- **Patient details** on customers: allergies, conditions, NHIS number and expiry. Only pharmacy staff see them.
- **Prescription-only and controlled sales at the till** need:
  - the patient;
  - the prescription details;
  - a pharmacist's username and PIN, or a pharmacist signed in on the till. This works offline through the offline sign-in; unapproved offline sales are flagged for review.
- **Allergy and interaction warnings** before payment, from the local interaction table. Generic substitution at the till.
- **Pharmacy menu:**
  - Prescriptions (RX numbers, linked to the sale);
  - Drug interactions;
  - Controlled-drug register (from the stock ledger, printable);
  - NHIS claims export (CSV).
- Roles **Pharmacist** and **Pharmacy Technician**; permissions `pharmacy.dispense`, `pharmacy.patients`, `pharmacy.manage`.
- Settings → Pharmacy: premises licence number, superintendent pharmacist.
## [0.12.0] — 2026-10-16 · Phase 9a Grocery, batches and expiry
### Added
- **Batches and expiry dates** for any product ("Batches and expiry dates" on the product): received with batch number and expiry (back office and handheld), sold earliest expiry first, expired stock cannot be sold online (offline sales are kept and flagged). Batches follow transfers and returns; switching it on for stock already held puts it in batch OPENING.
- **Stock → Batches & expiry**: batches on hand by expiry; suggested markdowns to apply; expired batches to write off; active markdowns and **markdown stickers** (EAN-13) to print.
- **Grocery module** (per branch): **scale labels** with the weight in the barcode (item scale code / PLU on the product), **markdown stickers** scanned at the till at their own price, **electronic shelf label** price feed.
- **Stock → Shrink**: losses at cost by reason and product. New adjustment reason "Spoilage".
- Settings → Grocery: scale prefixes, sticker prefix, markdown rules, shelf-label gateway address.
### Changed
- `erp:stock-check` also proves batch levels.
## [0.11.0] — 2026-10-15 · Licensing and anti-piracy
### Added
- **Licence keys:** every installation must be activated with a key from Synclytics (Settings → Licence shows the installation code). Keys set the expiry date, the number of branches, tills and devices and active users, the industry modules, and whether Finance and HR are included.
- **Expiry:** banner from 30 days before; 14 days' grace; then **read-only**: view only, no changes, no sales, no printing or downloads. Data is never deleted, and tills keep unsent sales.
- **Daily check-in** with the Synclytics licence server: renewals arrive automatically; licences can be withdrawn; signed answers.
- **Tamper detection** in release builds (signed manifest of the licensing code) and **per-customer release builds** with a hidden stamp (`release:build`).
- **Hidden watermarks** on printed documents, till receipts and pages, readable with `license:trace`.
- Commands: `license:status`, `license:checkin`, `license:trace`; Synclytics only: `license:keygen`, `license:issue`, `license:revoke`, `release:build`.
- Docs: licensing guide.
## [0.10.0] — 2026-10-14 · Phase 8 HR, attendance and payroll
### Added
- **Employees** (HR & payroll): personal, job and pay details (basic, transport and meal allowances, overtime rate, Tier 3, welfare), paid by bank, MoMo or cash; departments; end of employment.
- **Clock in / out on the till** with username and PIN — no sign-in needed; works offline for people who have signed in on that till before.
- **Timesheets**: hours and overtime per person per month; managers add a missing clock-out or cancel a wrong one, with a reason.
- **Leave**: Ghana statutory types (annual 15 days, sick, maternity, paternity, unpaid), balances, requests by staff (self-service) or HR, approval by the line manager.
- **Staff loans and salary advances**, repaid automatically through payroll.
- **Monthly payroll** on a seeded, accountant-confirmable Ghana profile (PAYE bands, SSNIT Tier 1/2, Tier 3 relief, junior staff overtime tax): prepare → approve (someone else) → pay; payslips (A4), cash pay sheet, bank/MoMo payment list (CSV); posted to the books automatically.
- **My leave & payslips** page for every employee with a sign-in.
- Permissions: hr.view, hr.manage, attendance.manage, leave.request, leave.approve, loans.manage, payroll.prepare, payroll.approve, payroll.pay (Core 0.10.0).
- API: `POST pos/clock`; queued `clock` events.
### Changed
- The chart gains payroll purposes (salaries, employer SSNIT, PAYE, SSNIT, Tier 2, Tier 3, welfare, salaries payable, staff loans), added to existing companies automatically.
## [0.9.0] — 2026-10-13 · Phase 7b banking, expenses, assets, budgets, VAT
### Added
- **Bank & cash** (Finance): bank, MoMo and cash accounts; **statement import from CSV** with a remembered column mapping (money in/out or signed amounts, Ghanaian date formats, totals rows skipped, re-imports skipped); **reconciliation** with suggestions, many-to-many matching, posting bank charges from the statement, and the reconciliation summary against the statement balance.
- **Money moved** between your own accounts (bank the till cash, top up petty cash, sweep MoMo).
- **Expense claims** with receipt photo: approved by the branch manager up to GHS 500 and by finance above, never your own; paid from petty cash or another account.
- **Fixed assets**: register, kinds of asset with useful lives, straight-line monthly depreciation posted automatically, disposal with gain or loss.
- **Budgets** per branch, account and month (spread a yearly figure), and the **budget vs actual** report.
- **VAT return** report: taxed and exempt sales, output and input VAT, NHIL and GETFund, and what is due.
- Permissions: banking.manage, expenses.claim, expenses.approve (limit), expenses.pay, assets.manage, budgets.manage (Core 0.9.0).
### Changed
- The chart gains petty cash, accumulated depreciation, depreciation, disposal gain/loss and bank charges purposes (added to existing companies automatically).
- Tests run with a 512 MB memory limit.
## [0.8.0] — 2026-10-12 · Phase 7a finance: general ledger and payables
### Added
- **Chart of accounts** (Finance → Chart of accounts): Ghana SME template installed for every company; edit names and codes, add accounts, choose which account automatic posting uses for each purpose.
- **Automatic posting** from every module into a double-entry general ledger: till sales and returns, stock at cost, customer invoices, credit notes and payments, online orders, shift cash over/short, loyalty points, gift cards, supplier bills and payments, opening balances. Runs every 5 minutes (`erp:gl-post`) or on demand (Journals → Post now).
- **Journals**: typed journals, an opening trial balance for go-live, and reversals. The database refuses unbalanced entries, edits and postings into closed months.
- **Month-end** (Finance → Month-end): close months in order once everything is posted and ties; late documents land in the next open month; only the GM/owner reopens, with a reason.
- **Reports** (Finance → Reports): trial balance, profit and loss, balance sheet, account ledger (filter by branch) and the ledger check proving the ledger equals customers, suppliers, stock, gift cards and points (`erp:gl-check`).
- **Supplier bills** with a 3-way match against goods received and order prices (differences over 2 % / GHS 50 wait for a finance manager), expense bills (rent, utilities), **supplier payments** (oldest bills first), supplier opening balances and **aged payables**.
- Permissions: finance.view, finance.journal, finance.accounts.manage, finance.close, finance.reopen, payables.view, payables.manage, payables.approve, payables.pay (Core 0.8.0).
- Docs: finance guide.
### Changed
- Stock coming in without its own cost (returns, count gains) is valued at the current average; a goods-received reversal leaves at the cost it came in at.
## [0.7.0] — 2026-10-11 · Phase 6b promotions, loyalty, gift cards and returns
### Added
- **Promotions** (Pricing & tax → Promotions): percent or amount off, buy X get Y, bundles / mix & match, spend-X basket offers, coupons; by dates, days, happy-hour times, branch and price list. The best single promotion per item, then basket offer and one coupon on top.
- One promotion engine in PHP and on the till, proven identical by shared test vectors; works offline, and the server re-checks online sales.
- **Till discounts**: line discount or price override and a sale discount, within role limits (supervisor 10 %, manager 25 %); a supervisor approves by PIN, offline too.
- **Loyalty**: 1 point per GHS 1, pay with points from 500 (100 points = GHS 1), 12-month expiry; synced to every branch.
- **Gift cards and store credit** (Sales → Gift cards & credit) as till tenders; expire 12 months after last use.
- **Returns** at the till (F8): by receipt within 30 days, refunded to the original tenders; without a receipt, store credit with a supervisor PIN.
- **Take account payments** at the till (counted in the shift cash).
- Receipts show promotions, "You saved", points and RETURN/REFUND; new layout: logo space, numbered item columns (SN, Item, Qty, Price, Total) running margin to margin, and a QR code of the receipt number (scan it on the Return screen).
- Commands: `erp:expire-balances` (nightly); `erp:receivables-check` also checks points and card balances; `erp:demo-sales` adds sample promotions.
- Docs: promotions, loyalty and returns guide; till API for promotions, returns and account payments.
## [0.6.0] — 2026-10-10 · Phase 6a customers and order-to-cash
### Added
- **Customers** (Sales → Customers): profile, price list, recorded marketing consent (who, when, how), and head-office credit (limit, terms, hold, opening balance). The customer page shows balance, overdue amount, available credit and open items.
- **Quotations → orders**:
  - priced from the customer's price list;
  - the credit check puts an order on hold;
  - release by someone else within their override limit.
- **Deliveries**: partial or full; each one raises its **invoice** automatically, due after the customer's terms.
- **Payments**:
  - settle the oldest invoices first;
  - leftover money becomes account credit, used on the next invoice.
- **Credit notes**: optionally take the goods back.
- **Statements** and **aged receivables**.
- A4 print/PDF pages for quotation/order, tax invoice, statement and payment receipt.
- **Till**: customer lookup (F4, works offline) with the customer's price list, and the **On account** tender. A supervisor approves by PIN when a sale goes over the limit; offline credit sales are kept and flagged.
- Sync: *customers* group (field-level merge with a conflict log) and *receivables* group, both relayed between branch servers.
- Commands: `erp:receivables-check`, `erp:demo-sales`.
- Docs: customers, wholesale sales and receivables guide; till customer API.
### Fixed
- A newly installed owner now gets the owner role's approval limits (PO approval, credit override).

## [0.5.0] — 2026-10-09 · Phase 5 purchasing and warehouse
### Added
- **Suppliers**: contacts, terms, lead time; products supplied with purchase unit, supplier code, last cost, minimum order, preferred.
- **Purchase orders**:
  - draft → submit → approve or send back;
  - the creator never approves; tiered approval limits (officer GHS 5,000, managers GHS 20,000, finance/GM/owner unlimited);
  - receive, close short, cancel;
  - tax worked out from each product's tax group.
- **Goods received (GRN)**:
  - posts at cost (weighted-average stock valuation);
  - over-deliveries above 5 % wait for a manager (accept or refuse);
  - a posted GRN is reversed, never edited.
- **Bins**:
  - switched on per warehouse, with a receiving bin;
  - put-away and move stock;
  - pick order for sales and transfers;
  - bin contents shown.
- **Transfers**: dispatch, in transit, receive with differences flagged for review.
- **Stock counts**: blind by default, frozen expected quantities, entered on screen or handheld, posted by a second person.
- **Reorder levels** (min/max) and **What to order**: suggestions in supplier cartons that count open orders and transfers in transit; one-click draft orders per supplier.
- **Warehouse handheld**: enrol a scanner phone as a handheld; PIN sign-in for storekeepers; receive by scanning, put away, receive transfers, count.
- Document numbers per server: PO-HQ-000001, GRN-…, TRF-…, CNT-….
- Sync: new groups *purchasing* and *warehouse*; head office relays stock and warehouse events between branch servers.
- Settings: over-delivery tolerance (%).
- `erp:demo-purchasing` sample suppliers, head-office bins and reorder levels.
- Docs: purchasing and warehouse guide; handheld API.
### Changed
- The device enrolment screen says "device" (tills and handhelds).
- `erp:stock-check` also checks bin levels, and that bin-managed warehouses equal the sum of their bins.
### Fixed
- A timing-dependent test of the 72-hour offline sign-in boundary.

## [0.4.0] — 2026-10-08 · Phase 4 live updates and online store
### Added
- Live updates over WebSockets (Laravel Reverb). Tills pick up price and stock changes within seconds ("Connected · live"), with polling as a fallback.
- **Online → Channels**: built-in shop, WooCommerce, Shopify and generic REST connectors.
  - Encrypted secrets, never shown again; webhook address; safety stock per channel.
  - Activate, pause, compare with store (reconciliation), retry failed updates.
  - Marketplaces listed as planned.
- **Online → Online orders**: orders from every channel with reserved stock, Mark ready, Hand over (records payment and issues stock), and Cancel (releases stock); scoped by branch.
- **Built-in online shop** (Storefront module, per branch) at `/shop/{address}`: live prices and availability, order and pickup checkout, and a confirmation page.
- Store webhooks: signed, deduplicated, stored before processing. Store pushes: retry with backoff, then a review item. Reconciliation every 15 minutes.
- Review queue types: oversold online order, store total differs, store update failed, store order not processed.
- Permissions: `orders.view`, `orders.manage`, `channels.manage`, added to the standard roles (`php artisan db:seed` after upgrading).
- Commands: `erp:relay`, `erp:channels:reconcile`.
- Docs: online store and live updates guide; API for webhooks and live channels.

## [0.3.0] — 2026-10-07 · Phase 3 offline tills and server sync
### Added
- Tills keep working without their server: offline PIN sign-in (signed, expiring offline grants), cached catalog, offline shifts and sales in an ordered queue, receipts marked "recorded offline", automatic sending in device-key-signed batches, "Retry refused", "Export unsent sales".
- Offline sales are kept as they happened and flagged in a new **Review queue** (old price, expired or revoked sign-in, wrong clock, negative stock, refused items); resolved with a note.
- Branch servers: pairing with head office (`erp:node:add-branch`, `erp:node:join`), automatic two-way sync every minute (`erp:sync`), HQ-owned master data read-only on branches, per-group Automatic / Manual (preview) / Off, full resync, USB sync files.
- **Sync Center**: peer status, queues, errors, sync now, review incoming, export/import, disable/enable, add branch server, import till file.
- Receipt series letter per till device (HQ-T01A-000123) so numbers never collide after a reinstall.
- Settings: how long an offline till sign-in lasts.
- Docs: offline and sync guide, branch server install, cashier offline section, API for the queue and server sync, security notes.
### Fixed
- Starter data and seeded roles now reach branch servers (they were created without sync events).
- The till treats proxy errors (502/503/504) from a stopped server as "offline".

## [0.2.0] — 2026-10-06 · Phase 2 catalog, stock and first till sale
### Added
- Catalog: products with SKU, category, brand, base unit, extra units (carton = 24), barcodes per unit, tax group; categories, brands, units of measure.
- Prices: Retail and Wholesale lists, prices per unit, quantity tiers, dated promotions; tax rates and tax groups with a recorded accountant check (Ghana starter set marked unverified).
- Stock: append-only stock ledger, stock on hand per warehouse, opening balances and adjustments with reasons; `erp:stock-check`.
- Till app: enrolment by one-time code, PIN sign-in, open shift with float, scan/search, units, Retail/Wholesale switch, split cash / mobile money / card payments, 80 mm receipt, blind shift close with over/short; unsent sales kept on the till with retry.
- Sale API: idempotent posting with server-side recalculation of every price, tax and total.
- Back office: Till sales and Till shifts; settings for receipt footer and negative-stock blocking.
- `erp:demo-catalog` sample range (21 Ghanaian grocery/wholesale items).
- Docs: pricing and tax rules, cashier guide, catalog and stock guide, API for the till.
### Fixed
- Back-office columns that showed blank or zero values (Roles level, Devices kind/status, Tax rates) because their display callbacks did not receive the value.

## [0.1.0] — 2026-10-05 · Phase 1 core platform
### Added
- Company structure: companies, legal entities, branches, warehouses, tills, devices (UUIDv7 ids, origin node and version on every row).
- Access control: security levels L0–L7, 22 seeded roles, grants scoped to company / legal entity / branch / warehouse / till with numeric limits and optional expiry; anti-escalation rules; IT Admin technical-only.
- Tamper-evident audit log (HMAC chain, append-only at the database, secrets redacted) with *Verify chain* and `erp:audit-verify`; transactional outbox for sync.
- Module system (`server/modules/*/module.json`): Core plus 14 planned modules, enabled/reserved/off per branch.
- First run: `erp:audit-key`, `erp:install`, then a back-office setup wizard (deployment mode, company, branches, tills, modules per branch).
- Back office: Branches (warehouses, tills), Users (access grants, POS PIN), Roles, Devices (one-time enrolment codes, revoke), Audit log, Modules, Settings.
- Sign-in security: two-factor required from L3 (authenticator app with recovery codes, or passkeys with user verification); audited profile and 2FA changes.
- Till API: device enrolment, PIN sign-in on enrolled devices with lockout, `me`, logout.
- Docs: security, modules, API, administrator manual.

## [0.0.1] — 2026-10-05 · Phase 0 scaffold
### Added
- Monorepo: `server/` (Laravel 13, Filament 5 back office at `/admin`, Pest on PostgreSQL 17) and `pos/` (React + TypeScript PWA shell with connection status).
- CI workflow: formatting, dependency audits, server and POS tests, POS build.
- Docs: README, CLAUDE.md, STATE, DECISIONS, REQUIREMENTS.
