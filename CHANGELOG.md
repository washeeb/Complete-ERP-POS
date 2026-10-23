# Changelog

## [Unreleased]
### Added
- **Mobile money through any other provider:** *Other provider (custom gateway)* under System → Mobile money — the gateway's https address and a shared secret; a small service a developer builds to the contract in `docs/momo-custom-gateway.md` connects Flutterwave, ExpressPay, Korba, Theteller, MTN's MoMo API or a bank. Signed callbacks, and the gateway is always asked before a payment counts. Manual 11.6.
- **Website copiers refused:** HTTrack, WebCopy, Offline Explorer, wget and similar tools get *Copying this site is not allowed* from the web server (Ubuntu and Windows Caddy, Docker nginx) and the application; visitors not signed in are limited to 120 pages a minute; every page says *noindex, nofollow, noarchive* and robots.txt disallows everything. Manual 13.
- **One-command preview:** `node pos/scripts/preview.mjs` (or *ERP preview* in the Claude app) builds the till into the server, updates the database and serves back office and till on port 8010. Manual 12.6.
- **GRA E-VAT, API version 8.2:** reporting now uses GRA's VSDC as GRA publishes it (company reference in the address, `security_key`, levies A–E per item, returns and credit notes as partial refunds of the original); receipts print GRA's signature block (SDC ID, receipt number, time, internal data, signature, MRC) with the QR code; System → Health checks GRA's VSDC; separate legal entities report with their own GRA account (`GRA_EVAT_ACCOUNTS`). New `.env` key `GRA_EVAT_COMPANY_REFERENCE`. Manual 11.5.
- **Support console (SyncLytics):** each installation sends an hourly health report to the licence server (System → Health checks, tills with problems, open problem reports, support access — never business figures; *Share system health with SyncLytics support*, on by default, can be switched off). On the licence server, **Licences → Support console** shows every client worst first (Needs attention, Not reporting for 3 hours, Warning, OK) with the details; red and silent servers also appear under *Needs attention* on the dashboard. Manual 6.3.
- **Mobile money that confirms itself:** with a Paystack or Hubtel merchant account (System → Mobile money), the till sends a payment request to the customer's phone (MTN MoMo, Telecel Cash, AT Money), waits while they approve with their PIN (or type the code they got), and adds the payment only once the provider confirms it; the sale shows it as *verified* with the provider's transaction ID, and a paid request can be used once. Setting *How the till takes mobile money*: typed ID (as before), request with typing allowed, or request only (typing only offline). Signed Paystack callbacks and Hubtel status checks; *Paid, not on a sale* for money that arrived after the till stopped waiting. Permission *Set up mobile money payment requests* (finance manager). Manual 11.6.
- **Refill reminders (Pharmacy):** a medicine taken every day gets *Used a day*; a sale of it to a named customer who agreed to texts becomes a reminder sent by WhatsApp or SMS a few days before it runs out (one message for every medicine due; buying again replaces it). **Pharmacy → Refill reminders** with Send now and Cancel; settings under *Refill reminders* (off until switched on); products file column `daily_use`.
- **Remote support relay (any VPS):** `installer/remote-relay/install-relay.sh` installs MeshCentral on any Ubuntu 22.04/24.04 or Debian 12 server with HTTPS, two-factor sign-in, no self sign-up and session recording. In the ERP, with `REMOTE_RELAY_URL` set: **View screen** and **Take control** on System → Devices (staff with the Remote access add-on and permission; SyncLytics support only while Support access is in force), each connection in the audit log, and **Install remote support** with the client's agent link (setting *Remote support agent install link*). Manual chapter 20.
- **Handhelds of every brand, and phones:** scan with the **camera** (📷 beside the scan box on the handheld and the till; Chrome on Android) where there is no scanner; a scan typed while the cursor is outside the box is still caught (handheld and till, never behind an open window); scanners ending with Tab as well as Enter; a beep and buzz on each scan. Manual 9.9: which handhelds to buy, and scanner set-up for Zebra (DataWedge), Honeywell, Sunmi, Urovo, Newland, Datalogic and Bluetooth/USB scanners.
- **The server tells you when it needs attention:** every hour the health checks run, and a failed or late backup, a failed restore check, a missing backup passphrase, a nearly full disk or memory, an overloaded server or a read-only licence is sent to the owner and IT Admin on the bell and by e-mail (once a day per problem). **System → Health** shows server load and memory on Linux.
- **Daily summary of the business** (System → Settings → Daily summary, off by default): each evening, sales by branch against the same day last week, cash / mobile money / card, cash over or short, low / expired / expiring stock, losses and unusual losses, and approvals waiting — to holders of the new *receive the daily summary* permission (Owner, General Manager), on the bell, by e-mail and optionally a short WhatsApp.
- **The company can switch the SyncLytics support account off** (System → Settings → Security).

### Changed
- **The till no longer waits for a slow network.** A sale waits at most 4 seconds for the server; then it is kept on the till, the receipt shows and prints at once, and the sale follows from the till's queue (the server ignores a second copy). Sales with a supervisor's PIN still wait for the server. No till call waits longer than 20 seconds.
- **The desktop till prints each receipt by itself** on its receipt printer the moment the sale is done (setting *Desktop till prints each receipt by itself*, on by default), instead of waiting for **Print receipt**. Asked for by the first client.

### Added
- **Slow & dead stock** (Stock Controller release 4): what has not sold in a store for 60/90/180 days, its value at cost, and a suggestion — **Move** to the store where it sells (draft transfer in one click), supplier take-back, or mark down.
- **Shrink report:** the last six months month by month, by category, by who recorded it, and products lost three times or more.
- **Smart checks on My day:** losses larger than usual (3 × the store's usual loss line, at least GHS 100), products written off three times or more in 30 days, and possible duplicate products.

### Fixed
- *Stock → Shrink* no longer shows another branch's losses when that store is typed into the address.
- **Barcode and shelf labels** (Stock Controller release 3): **Print labels** on the products list (ticked products), on a product and on a goods received note — shelf labels or barcode stickers, on A4 label sheets (24, 21 or 65 a sheet; skip labels already used) or a label printer (50 × 25, 38 × 25, 58 × 40 mm), with prices from a chosen list. Products without a barcode can be given an in-store EAN-13 first (prefix setting, default 20). EAN-13 or Code 128 bars (picqer/php-barcode-generator).
- **Photos on stock adjustment lines** (Details → Photo of the goods), shown on the adjustment to people who may see that store's stock; setting *Photos on stock adjustment lines*: optional (default), required for damaged / expired / spoiled / missing stock, or not used.

### Fixed
- Two broken characters in the manual (*batch_no*, *expires_on* in chapter 7).
- **Stock → My day** (Stock Controller release 2): per store, today's cycle count, low stock, expired and expiring batches, negative stock, never counted, adjustments waiting for a manager, transfers and orders on the way; each opens its page.
- **Cycle counting:** a few products per store each day (setting *Cycle count: products per store per day*, default 20), chosen by value moved in 90 days (A every 30 days, B 60, C 120), never counted first, *often wrong* products twice as often; **Start today's count** makes a normal blind count.
- **Training: New features — switch on and check**, a one-hour checklist for the session after an update (receipts, dosage, payment alerts, automatic shifts, sign-in, menu search, batches).

### Fixed
- Settings help and the manual named three screens wrongly (*System → Messaging*, *System → Import data*, *Cash → Shifts and vaults*).
- **"You can't open this page" says why:** the permission that is missing (in words, and where), who can give access, and **Ask for access**, which tells them on the bell (audited). Every link the back office shows was checked for every role, company-wide and at a branch, with sample data (menu, dashboard, list rows, New and Print): the ones that failed are fixed. Asked for by the first client.
- **Search the menu** at the top of the side menu (`/` to jump there): matching pages stay with their sections opened; Enter opens the first. Asked for by the first client.

### Changed
- **Receipts and documents carry the company the branch trades as** (its industry's legal entity, e.g. Asante Pharmacy Ltd), not the group; the receipt top is shorter: name, branch · phone, TIN / VAT (no address, no second name). Same name on dosage labels and drawer slips, and at the top of the back office for people who work in one industry. Asked for by the first client.

### Fixed
- *Grocery → Markdowns* failed for branch staff where Grocery runs at another branch (now hidden for them), and *Batches & expiry* no longer shows a store the person may not see when its address is typed in.
- The report builder's *Stock on hand* failed to open its rows.
- The sample-data commands (`erp:demo-catalog`, `erp:demo-purchasing`, `erp:demo-sales`) failed or skipped products since names are kept in capitals.
- **Dosage at the till:** each medicine's *usual dosage* (product page or the `dosage` column), shown under the medicine on the sale and changeable for the patient; printed on the receipt and on a **dosage label** per medicine (pharmacy, patient, medicine and quantity, directions, date, receipt number, *keep out of reach of children*) — by itself on the desktop till's receipt printer, *Print dosage labels* in a browser. Shown on the sale in the back office. Asked for by the first client.
- **Mobile money and card payment alerts** (System → Settings → Payment alerts, off by default): each payment and/or a daily summary, to holders of the new *receive payment alerts* permission (Owner, General Manager, Finance Manager, Accountant) on the bell, and by e-mail, SMS or WhatsApp when switched on; minimum amount for single alerts; summary time. Sent through the relay, so a till never waits. Asked for by the first client.
- **Automatic shifts** (Settings → Tills and cash, off by default, per branch): each till session is named by its shift (Cash → Shifts and vaults), a session of 10 hours or more is a *Straight day*; a till opens only within the trading hours (default 07:00–23:00) and once per shift, otherwise with a manager's username, PIN and reason (new permission *let a till open outside hours or again*, Branch Manager and above), shown on Till shifts. Shifts may overlap by at most an hour. Asked for by the first client.

### Changed
- Default shifts are now Morning 07:00–15:00 and Afternoon 14:00–23:00 (owner); companies still on the first defaults (07:00–14:00, 14:00–21:00) are moved to them, changed times are kept.
- **Correct an expiry date, add or move a batch from the screen** (Stock → Batches & expiry): *Correct expiry* on any batch (with the reason, audited; the stock stays put); *Add or move a batch* for a batch the system does not have, or stock under a wrong batch number, as a draft stock adjustment to check and post. Asked for by the first client.
- **Sign in with the username** as well as the e-mail address (the same username as on the till).
- **Sign-in method per person**, set by the administrator on the user: the company rule, password + authenticator app, **password + a code by WhatsApp or e-mail** (6 digits, once, 10 minutes; the code is never stored or logged), or **password only** (staff up to supervisors). Users get a *Mobile (WhatsApp) number*; the list shows how each person signs in. New message wording *Sign-in code*. Asked for by the first client.
- **SyncLytics support account (Superadmin, L7):** added on the server with `php artisan erp:support-account` (the password is typed, never on the command line), never from the back office. Above the owner so it cannot be changed or locked out by mistake; always with the authenticator app; not counted as a licensed user; the owner and general manager are notified at every sign-in. Asked for by the first client.
- **Purchase orders from a file:** *Order from a file* on Purchasing → Purchase orders turns an Excel or CSV list (SKU, supplier code, barcode or name; quantity; optional unit and cost) or a supplier's **pro-forma invoice read by the AI** (photo or PDF) into a draft order to check and submit. Lines that cannot be matched, or have no cost, are listed, never guessed. **Order again** copies any earlier order into a new draft at the latest prices. Asked for by the first client.
- **Send purchase orders to suppliers** from the order: e-mail with the order as a PDF, WhatsApp opened on this computer with the message and a 30-day download link (no provider needed), or WhatsApp sent by the system when a provider is set up. The order shows when, how and to whom it was sent; every message is in the message log. New message wording *Purchase order sent to a supplier*. Asked for by the first client.
- **What to order: change before ordering.** *Change* on a line picks another supplier (the product's own suppliers first, with their last price), unit, quantity and cost, and — with the reorder-levels permission — the product's minimum and maximum for the store; *Undo change*; filter by supplier or *No supplier set up*. Asked for by the first client.
- **Progress you can see:** imports and long bulk changes (change the unit, move products, set the medicine schedule) show a card with *Checking products.csv — 150 of 2,000* and the time left, so nobody clicks again or leaves the page.
- **Print preview** for purchase orders, goods received, stock transfers, stock requests and purchase requests (Print opens it in a new tab: print or save as PDF), with signature lines.
- **Expiry at the till:** batch items show the expiry of the batch sold next in the search list and on the sale line, amber within 90 days and red once expired.
- **Supplier codes by the system** (SUP-HQ-000001) when left empty; a typed code still wins.
- **Device health and remote fixes (remote support phase 1B).** Tills and handhelds report every few minutes (version, unsent work, connection, storage, printer, catalogue age); **System → Devices** has a *Health* column in plain words, and IT is told once a day per problem (offline during trading hours, sales stuck over 30 minutes, refused items, storage nearly full, old till version, repeated errors). With the Remote access add-on, **Send a fix**: reload, send waiting sales now, refresh the catalogue, clear the cache (never unsent sales) or collect logs; the till runs it within about five minutes and the result is listed under *Fixes*. Audited.
- **Remote support, phase 1.** **System → Support access**: the owner lets SyncLytics support reach the company's tills and computers (*see only* or *see and control*; every branch or one; for a few hours or until a problem report is closed), free of charge, with a banner for everyone while it is open and *Withdraw now*; support is told by e-mail. **Remote access** for the company's own staff (training, IT) is a paid licence add-on (`remote_access`, not included in "all features"), with the permissions *see device screens* and *control devices*. **Consent per branch** (System → Settings → Remote access): unattended outside trading hours by default, always unattended, or always ask. The screen-sharing relay itself comes in phase 2.
- **Report a problem with a picture of the screen:** the till takes it by itself (it can be left out); in the back office paste it (Win+Shift+S, Ctrl+V) or attach it. It is e-mailed to support with the report and opens from the report. Whoever may give support access can let support connect until the problem is solved.
- **Change a product's unit, e.g. Pack or Strip to Tablet** (product page → *Change the unit*; ticked products on the list; or the *Unit changes* import with each product's pack size). *Convert by pack size*: stock in every store and batch × the pack size at the same value, cost and the new unit's price ÷ it, reorder levels × it, the old unit kept as a selling unit; recorded as stock leaving in the old unit and returning in the new one, so the history is never rewritten. *The label was wrong*: same numbers (now also when the product already had the new unit unused). Refused while the product is on an open document. Asked for by the first client.
- **Units of measure:** the code can be edited (products, tills and new documents follow; past documents keep theirs), and **Merge into…** joins a duplicate unit (PCS into PC), moving every product; files that still say PCS land on PC.
- **Purchase requests** (Purchasing → Purchase requests): the stock controller lists what a store needs (with what it holds now) and the buyers are told; a buyer **orders** it (a draft purchase order to the chosen supplier, priced from their last price or the average cost) or **rejects** it with the reason; the person who asked can cancel it. Numbered PRQ.
- **Stock Controller can now set reorder levels and accept suggested levels, create bins and shelf locations, and raise purchase requests**, and gets the morning low-stock alert. Three new permissions (*set reorder levels*, *bins and shelf locations*, *purchase requests*) so these no longer need the warehouse manager's or buyer's rights; switching a store to bins stays with the warehouse manager. A new training guide for the role. Asked for by the first client.
- **Drawer slip:** after a cash drop, pay-out or pay-in the till prints a slip (receipt printer on the desktop till; print window in a browser): amount, reference, time, till, what it was for, the cashier and the supervisor, with signature lines (cashier; supervisor for a drop or an approved pay-out; the person paid for a pay-out). *Print another copy* for the safe bag or the expense file.
- **Stock Controller role** (level 3): create and edit products, units, barcodes, categories and brands; opening stock and stock adjustments, stock counts, expiry, receiving goods, transfers and stock requests. No prices, approvals, selling, money, people or settings: adjustments above the approval limit and counts are posted by a manager. Give it for the whole company (products belong to every branch). Added to every company on update. Asked for by the first client.
- **The till drawer: expenses paid out, pay-ins, cash drops, no-sale opens and a cash limit.** A **Drawer** button on the till: *Pay out* an expense from the drawer (what it was spent on, who was paid, what for, receipt number and photo; a supervisor approves by PIN above the limit, default GHS 100), recorded as an expense claim paid from the till and posted to the books; *Pay in* cash with a reason; *Cash drop* to the safe with the supervisor's PIN; *Open drawer* without a sale with a reason. Each changes the cash expected at close. A *cash limit* (System → Settings; off by default) shows a bar on the till asking for a cash drop and notifies supervisors. Works offline. New **Sales → Till drawer** list; the Till shifts list and shift report show the drawer; loss prevention compares no-sale opens per cashier. Asked for by the first client.
- **Close shift also checks vouchers and coupons:** the value of gift vouchers and credit notes taken and the number of paper coupons collected, against the system; the shift report lists each coupon used and the discount given.
- **Close shift checks mobile money and card, not only cash.** The cashier types what the MoMo merchant phone shows for each network (MTN, Telecel, AT) and the card terminal's total, without seeing the system's figures first; the result shows each as entered, expected and over / short (on a vault too, since MoMo is not in the tray). Expected amounts include sales, returns, account payments taken at the till and booking deposits. **Sales → Till shifts** has a *MoMo & card* column and a **Shift report** (PDF: cash, takings by payment method, the MoMo / card check). Asked for by the first client.
- **Move products between categories, brands and tax groups, and delete empty ones.** Products list: tick products → *Change category, brand or tax*. Categories and Brands: *Move products* moves all of one to another (also how two duplicates are merged), and *Delete* removes a category, brand or unit that nothing uses — otherwise it says what still uses it. Each list shows its number of products. Deletions are audited and reach branch servers. Asked for by the first client.
- **Till: Details, comment and calculator.** *Details* takes a walk-in's name and phone without creating a customer (saved later with *Save as customer* on the sale), a comment that can be printed on the receipt, and industry details (pharmacy: age, sex, weight, pregnancy; restaurant: guests; services: next visit). A branch can require a name and/or phone before payment (Settings → Tills and cash, or per branch); offline sales are never refused, a missing detail goes to the review list. **Calculator (F1)** on the till (percent like a shop calculator, *Use as amount* on the payment screen) and in the back-office top bar; the payment screen shows the change in notes and coins.
- **Waybills** (Stock → Waybills, number `WB`): for deliveries to customers, transfers between branches and returns to suppliers, plus the supplier's own waybill on goods received. The goods are copied from the document; transporter, driver, vehicle, packages and weight; print with signature lines and a **QR code the receiver scans to confirm delivery** on their phone (name, optional photo, note); dispatch, mark delivered with the signed copy or a photo, cancel. Shortcuts on transfers, delivered customer orders, supplier returns and goods received.
- **Licence generator for SyncLytics** (`/vendor`, on the licence server only; never in client builds). Customers, plans and add-ons; issue licences from an installation code with a package (limits, industry modules, Finance / HR, length, price) or custom terms; renew, change package (a new key that replaces the old one at the next check-in), revoke, e-mail the key, print a licence certificate. Invoices with payments (ABSA, MoMo, Telecel Cash), balances and overdue. Dashboard: active / ending / ended / revoked, yearly revenue, money owed, and what needs attention from the daily check-ins (silent, modified, extra machines, over the limits). Renewal reminders by e-mail and WhatsApp 60, 30 and 7 days before the end. Authenticator app required; your password again before signing or revoking. CSV of the whole licence register.
- **How strictly prescription medicines are sold is now a choice** (Settings → Pharmacy, and per branch). For prescription-only and for controlled medicines: pharmacist approves with the prescription (default, as before), prescription details only, patient only, or sell freely. Controlled drugs can be locked to pharmacist approval; a quantity limit and major allergy or interaction warnings still need a pharmacist. Sales made without a pharmacist are listed on **Pharmacy → Sales to sign off** for a pharmacist to sign (or summarised daily), with a CSV for inspections. The till asks only for what the branch requires. Asked for by the first client.
- **Prescription-only and controlled medicines in bulk.** The products file now carries `drug_schedule` (otc / pom / controlled), generic name, strength, form, FDA registration number and active ingredients, in both the upload and *Download current data*. The products list shows each medicine's schedule, filters by it, and **Set medicine schedule** classifies the ticked products at once. A schedule switches on batches and expiry. Before, a medicine could only be classified one at a time on its product page. Asked for by the first client.
- **Every product has a minimum stock.** A company default of 5 of the unit each product is bought in (5 packs, not 5 tablets), ordered up to twice that, for new and existing products (System → Settings → Stock; 0 = off). Your own figure per product and store replaces it (Purchasing → Reorder levels, in any unit, for one store or all; or `min_qty` / `max_qty` in the products file). **Smart levels** worked out every Monday from 12 weeks of sales, delivery time and a safety margin: suggested on **Purchasing → Suggested levels** to accept (default), or applied automatically without touching your own figures. Optional: no default minimum for products not sold in 180 days. **Low stock**: dashboard count, a morning notification to buyers, and an amber **Low** mark on the till. *What to order* now covers every product. Asked for by the first client.
- **Expired stock in a few clicks: Stock → Expiry & returns.** Lists every batch that has expired or expires soon (days left in colour, quantity, value at cost, store, supplier, controlled drugs), with filters. Tick the batches and choose:
  - **Dispose** — method, date, witness and (if the company asks) a certificate; prints a disposal record with signature lines, and a **disposal register** per store and period;
  - **Return to supplier** — for a credit note, replacement stock, a cash / MoMo / bank refund or nothing; prints a return note for the supplier to sign; **Stock → Returns to suppliers** records the settlement;
  - **Mark down** — stickers for stock that can still be sold.
  Every disposal and return is approved by a second person with *approve stock adjustments*, whatever its value; approvers are notified. Controlled drugs always need a witness. New permission **See expiring stock and prepare disposals and returns** (`stock.expiry`, pharmacists, pharmacy technicians, storekeepers and managers). The accounts follow automatically (new account 1350 *Goods returned to suppliers*). New settings (Stock): **warning period** (90 days), **stop selling a batch N days before it expires** (0 = off) and the **evidence kept for disposals** (method and witness by default). Each supplier can have a **return window** (days before expiry). The pharmacy dashboard shows expired and expiring batches. Asked for by the first client.
- **Clear the test trading before go-live:** `php artisan erp:clear-test-trading` lists, and with `--apply --confirm="Company name"` removes, everything traded while testing (till shifts and sales, cash, stock movements and levels, stock and purchasing documents, invoices and payments, prescriptions, loyalty, all accounting entries), after taking a backup. Products, prices, customers, suppliers, staff, devices and settings stay; the audit log keeps the test period and records the clearing. Refused once the company has gone live, or with branch servers. Asked for by the first client before going live.
- **Stock sheet by batch and expiry date:** products tracked by batch (most medicines) get one row per batch with its number, expiry date and quantity. Importing it sets each batch's quantity, adds a new batch with its expiry, and corrects a mistyped expiry date (at once, audited). Before, batch-tracked products could not be updated from the sheet at all. Asked for by the first client.
- **Product location / shelf** ("Shelf B2"): on the product screen and list, in the products import and export, on the stock sheet, and in the till's search list. The stock sheet also shows the bins holding each product, in a warehouse that uses bins.
- **Till shifts: Close shift and Correct the count** (managers, cash set-up permission). Close a shift no till can close any more (till reinstalled or replaced) with the cash there and why; correct a mistyped count with the right amount and why. The shift keeps its original count, the correction is its own record (✎ on the list, hover for details), the difference is posted between cash and cash over/short, and both are audited. Found at the first client.
- **Reprint a till receipt from the back office:** Sales → Till sales → a sale → **Print preview (copy)** or **Print copy** (opens the print window straight away). An 80 mm receipt marked *COPY, not the original receipt* with who reprinted it and when; every reprint is audited (`sale.receipt_reprinted`).
- **Till: Print receipt and Show print preview.** Print receipt prints straight to the desktop till's receipt printer (and says so when none is chosen); Show print preview always opens the print window first.
- **System → Computers:** every computer (browser) that used the back office gets a lasting ID and is listed with who used it last, when and from which address; name it ("Front desk PC") and the audit log shows that name. Audit log filter by computer; click an address to see roughly where it is (ipinfo.io, opened by you, nothing sent automatically). The desktop till's set-up page can **Open the back office** in the app, which then also records the computer's name and MAC address.
- **Prices in the till's search list:** each item shows its price on the price list in use (and each pack size's on its button), beside the stock left, so a teller can check a price without adding the item.
- **Which computer did what:** the audit log records the browser and system for every change and, from the desktop till app (0.25.0), the computer's name, MAC address and address on the shop network. **Sign-ins** (back office: in, out and failed; tills) are in the audit log with a filter; **System → Devices** shows where each till was last used from. Browsers never reveal a computer's name or MAC address, so these come from the desktop till only.
- **Pharmacy and clinic roles:** Superintendent Pharmacist (L4), Medicine Counter Assistant (MCA, L2), Doctor / Medical Officer and Physician Assistant (L3), Nurse and Midwife (L2), Laboratory Technician (L2), beside Pharmacist (L4) and Pharmacy Technician (L2). Only pharmacists dispense prescription medicines; the others see patient records. Offered only where Pharmacy runs; added to existing companies on update.
- **Other AI models:** *System → Settings → AI → Another cloud model* uses Google Gemini, Groq, OpenRouter, Mistral, OpenAI or any OpenAI-compatible service (several have free plans), with its key in `.env` (`AI_CLOUD_API_KEY`). Masking and "keep personal data on your server" apply as for Claude; the monthly budget stays Claude's.
- **The software maker's contact on receipts:** under "Powered by", till receipts (printed and on screen) and printed documents show *Software by SyncLytics: 024 563 6889* and the website. Not shown with a white-label licence.
- **Show stock left on the till while selling** (setting, off by default; asked for by the first client). Each item in the sale and in the search list shows how many are left at the till's store in its base unit (*14 TAB left*), counting what is already in the sale and sales not yet sent; *None left* in red when the sale takes more than the system shows. With the setting off, the till shows no stock figures (the search list showed them before).
- **Pharmacy units:** Tablet, Strip, Ampoule, Vial, Tube, Bottle, Pessary, Suppository and Container (Pack already existed) are added when Pharmacy is switched on, and for companies already running it. Units the company already has (same code or name) are kept as they are. Imports accept a unit by code or name (`TAB`, `Tablet`, `Strips`, `Cont.`). Asked for by the first client.
- **Guide for setting up a new build PC** (`docs/training/09-new-build-pc.md`, internal): what to back up, the tools (including Rust and `npx tauri build` for the desktop till installer), restoring the signing key and client pack, releases and licences, and what to do when a PC is lost.
- Manual 9.7: the desktop till's server address is the server's name, never its IP; how to clear the till's settings and start again. Troubleshooting entry for *Could not reach*.
- **Case study:** installation guide Chapter 19, a real cloud installation (Contabo, Namecheap, Windows `scp`/`ssh`, install script, licence, checks) with the problems met on the way; training checklist *Cloud installation* for SyncLytics staff.
### Fixed
- **A purchasing officer for one branch can add suppliers** (suppliers serve the whole company; before, only a company-wide grant could).
- **Till: "No offline sign-in for you on this till" at payment.** A cashier's till sign-in ended 12 hours after signing in, even in the middle of a busy day; the till then tried to keep the sale offline and showed a misleading message. Now the sign-in lasts 12 hours from the *last use* (an idle till still ends), and if it does end the payment screen says so and takes the cashier's username and PIN, then completes the same sale. Found at the first client.
- **Move products now moves them all.** Moving every product out of a category or brand (e.g. *Others* into *OTHER*) stopped at the first older product that breaks a newer rule (such as a medicine without batches), so nothing moved. A move now changes only the category, brand or tax group (still audited and sent to branch servers), and moves more than 200 products without skipping any. Found at the first client.
- **Shorter stock lines.** On stock adjustment lines, expiry, reason and note sit under **More details** (opens with a click, and by itself when one of them is filled). Serial / IMEI numbers are asked only for serial-numbered products, on adjustments and transfers. The opening-stock *Unit cost* help no longer mentions "Phase 7": it says the cost values your stock and works out profit. Asked for by the first client.
- **Products that never had stock now show on Stock on hand** (at 0), in each store of their industry, from the day they are created; new stores list every product too. Before, a product only appeared there after its first movement. A *Stock* filter shows in stock, out of stock (0) or negative. The stock sheet lists only the store's own industry and shared products. Found at the first client.
- **No double posting.** After an import the page starts clean (the file picker is emptied); importing the very same file again is caught and needs *Import it again* ticked; while any button's action is running it cannot be clicked again (everywhere in the back office); the timesheet and bank reconciliation forms clear after posting. Asked for by the first client.
- **Editing a product no longer says its own barcode "already belongs" to it.** Changing a product's base unit (before stock) created a second base unit and left the barcode on the old one. Now the base unit is renamed in place, its prices and barcodes follow, a barcode moves between units of the same product, and a product never has two base units. A barcode on *another* product is still refused, now naming its SKU. Found at the first client.
- **Add a missing category, brand, unit, supplier, customer or department from the form you are on** (the "+" next to the list): products, styles, purchase orders, supplier bills, customer orders, appointments, memberships, gift cards, staff, returns to suppliers. Same rules, permissions and audit log as their own screens. If what you type already exists (same name, code or phone), the existing one is selected instead of a copy; categories, brands and unit codes can no longer be added twice in any letter case.
- **A wrong base unit can be corrected after stock has moved** (e.g. Pieces → Pack). *Correct the unit* on the product page (or a different `base_unit` in the products update file) renames the unit: 30 pieces become 30 packs; prices, barcodes, stock and history stay; it is in the audit log. To sell in packs as well as pieces, add the pack under *Other units* instead. Found at the first client.
- **The search bar at the top finds everything, not only products.** Customers (also by phone typed as 024…), suppliers, sales receipts, invoices, orders, payments, purchase orders, deliveries, supplier bills and returns, stock transfers, counts, requests and adjustments, prescriptions, expense claims, staff, users, accounts, categories, brands, price lists and more: 37 lists in all, each person seeing only what they may open. Each result shows its details (products: the **SKU** and category, found by name, SKU or barcode; documents: the customer or supplier and status). A record without its own page opens its list filtered to it. Product pickers in forms show the SKU everywhere. Found at the first client.
- **Update products: Download current data was empty.** The update page does not ask for an industry and fell back to shared products only; it now gives every product, or those of the industry chosen at the top. Found at the first client.
- **Opening stock takes several batches of one medicine.** Only the first batch of a product went in; each further batch row was refused as stock history. Now each batch has its own opening balance (with its expiry); the same batch twice, or a product already sold, received or adjusted, is still refused. Found while preparing the first client's go-live.
- **Ubuntu installer: updates no longer stop when Launchpad is unreachable.** Re-adding the PHP package source asks Launchpad's website; an update now skips it when the source is already there, and a first install tries three times. Found when updating the first client.
- **Product imports can no longer create duplicates.** One import used to create and update: a row without an SKU always made a new product, so importing a file again to update it created second copies. Now **Products — create new** refuses rows whose SKU or name already exists, and **Products — update existing** changes products by SKU and never creates. `php artisan erp:merge-duplicate-products` (show, then `--apply`) cleans up earlier duplicates: it keeps the first copies, moves barcodes onto them, removes unused copies and switches off used ones, all audited. Found at the first client.
- **Renamed accounts are traceable without cluttering records.** Records and prints keep the name used at the time. Where the account has been renamed since (shifts, till sales, the audit log, orders, prescriptions, stock documents and more), a small icon shows and hovering gives today's name. **People & access → Users → the person → Name history** lists every change of name and username, with when and who.
- **The new-user form no longer fills in your own sign-in.** The browser's saved username and password appeared in it; it and every other password, PIN and key field now tell browsers they are new details. Found at the first client.
- **The audit log now has the visitor's real address on servers behind the web server** (Caddy). It recorded 127.0.0.1 for everything.
- **SKUs no longer start with "SKU".** Products shared by every industry get a plain number from 100001 (no leading zero for Excel to drop); an industry's products keep its code (`PHARMA-000123`). Existing `SKU-000123` codes are renamed once when the update is installed (shared: `100123`; in an industry: its series), audited and synced. A product moved to another industry takes that industry's series if its SKU was given by the system; SKUs typed in from an old system never change.
- **Product imports go to the right industry.** Imported products had no industry, so they showed in every industry's catalogue. The import page now asks **For which industry?** (or *Shared by all industries*) and refuses to check or import until it is chosen; an optional `industry` column can name another for single rows, and *Download current data* gives the chosen industry's products. **Move to industry** on the products list fixes products already in the wrong one (stock and prices stay; system-given SKUs take the new industry's series). Found at the first client.
- **You can see where you are in the back office.** The current menu item has the brand colour, a bar on the left and a bold label, its menu group's heading is coloured too, and the current tab is underlined, in light and dark mode (before, dark mode showed a near-invisible tint). **File pickers** (*Choose File* on Import data and Branding) are now proper buttons with a hand cursor. One stylesheet, `public/css/seslabs/admin.css`, loaded on every back-office page.
- **A long import no longer holds up the whole system.** A file was imported in one transaction, holding the audit log for minutes, so exports, sales from the tills and every other change waited behind it (downloads "took forever" and the back office felt slow). Rows now go in batches of 50; all or nothing is kept by checking the whole file first. Opening balances and stock adjustments, whose rows make one document, stay in one piece. Found at the first client.
- **System → Health no longer fails with a server error.** The scheduler stored its last-run time in the cache as a date object, which Laravel 13 will not read back from a server's file or database cache. It is now a plain timestamp; an old value is ignored until the next run a minute later. Found on the first client server.
- **Faster back office over long or unsteady lines:** the Ubuntu installer opens 443/udp, so browsers can use HTTP/3 (fewer round trips, copes with lost packets). Found at the first client, whose shop reaches the server in Europe in 150–230 ms.
- **Importing a long product list no longer fails with "Error while loading page".** Checking or importing a few thousand rows takes minutes, and the server stopped every request after 30 seconds. Found at the first client.
- **Barcodes keep their leading zeros through Excel.** The template and current data download as Excel (CSV still offered), with codes as text; CSV downloads write such codes as `="0123…"` so Excel keeps them. On import, zeros Excel dropped are put back when the check digit proves them (EAN-8 and UPC-A), whole numbers Excel wrote as `6001067000123.0` or in full scientific form are turned back into digits, and a barcode Excel shortened (`6.00107E+12`) is refused with how to fix it.
- **Desktop till: a wrong server address can always be corrected.** The app now checks that the server answers before saving it, and starts on its set-up page, which opens the server when it answers. A server that has answered before still opens when it cannot be reached (selling offline); one that never answered (a mistyped address, an IP address instead of the name) brings the set-up page back with an explanation. Before, a wrong address opened a blank window with no way back, and reinstalling did not help because uninstalling keeps the settings. Found at the first client's shop.
- **Cash count by notes and coins laid out properly**, on the till's *Close shift* and in the back office's cash desk and income control. The boxes no longer overflow their columns. Notes and coins sit in their own groups (side by side on a PC, one under the other on a phone), each line shows what it comes to, and the running total sits underneath. Still blind: the expected figure is never shown.
- **Only what the business runs is offered** (owner: "for pharmacy, kitchen things should not show"):
  - device types: a kitchen display only at a branch with the Restaurant module; the self-checkout kiosk (not built) never;
  - roles: industry roles (pharmacist, waiter, kitchen staff, technician, tailor, stylist, sales reps, production roles, rider) only where the industry runs, and HR roles only with HR licensed. A role someone already holds stays listed;
  - settings: industry sections (Restaurant, Pharmacy, Grocery, Fashion, Services, Distribution) only where the industry runs. Saving no longer touches the hidden sections' values;
  - message templates: table bookings only with Restaurant, appointments only with Services.
- **Ubuntu installer no longer stops silently at step 9** on Ubuntu 24.04 (the SSH port lookup failed when SSH is started on demand). Any unexpected stop now names the line. Found on the first client installation.
- **The signed release manifest now reaches servers** (`bootstrap/release-manifest.json`). It was in `storage/`, which installers keep on updates, so the tamper check and the client build stamp were silently off. `release:build` also includes the server's built assets (the online shop's live updates).

## [0.34.3] — 2026-10-14 · Shared server option
### Added
- **Several small clients on one Ubuntu server** (owner: one server per client is the standard; sharing is an option for small businesses starting out).
  - `install.sh --instance <name> --domain <name's address>` installs a separate copy with its own folder, database and role, system user, PHP-FPM pool (on demand), live-updates port (8081 and up), cron, Reverb service, backups, licence and update timing.
  - Caddy now uses a main file importing `/etc/caddy/sites/*.caddy`, one site file per copy. The standard installation behaves as before.
  - Private storage (backups, uploads, sessions, logs) is closed to other users of the server.
  - CI installs a second copy beside the first, then checks both answer, the second cannot be read by the first, and an update keeps its port.
- **Docs:** Chapter 5.0 (shared server: install, update, sizing, moving a client out, removal); Contabo example in 5.1.

## [0.34.2] — 2026-10-13 · Speed and clean-up
### Changed
- **Names in capitals:** product, generic name, category, brand, unit, supplier and customer names (and contact names) are stored in capitals for one uniform list; addresses, notes, e-mails and passwords are never changed. Names already in the system are converted once when this version is installed.
- **After posting, approving, sending or cancelling a document its page opens again**, so it shows the new status and only the buttons that still apply (no Post / Edit left active). On every document page.
- **The menu section you are in stays at the top of the sidebar.**
- **Stock adjustment lines on one row, numbered:** product, change, quantity, **Available** (and of the batch, when given), **After** (red below zero) and **Value** at average cost, with the adjustment's total and a note when a manager must approve it. Batch, expiry, unit cost, reason and note sit behind a **Details** drop-down at the end of the line (open by itself for batch-tracked products; what is typed is kept while it is closed). A warning when the same product and batch is on two lines. **Transfers, stock requests, purchase orders and customer orders** show the stock available (both stores on a request) and the same warning; every line form in the back office numbers its lines. Asked for by the first client.
- **PHP code cache on Windows:** the installer now switches on OPcache, which was off, so PHP no longer recompiled the application on every request. It also sets a larger file-location cache. Ubuntu and Docker get the same tuning.
- **Screens cached at install and update:** `php artisan filament:optimize` runs after `optimize` in the Windows, Ubuntu and Docker installs (Blade components and icons).
- **Browser caching:** versioned files (till code, back-office styles, scripts and fonts) are kept by browsers for a year. Docker's nginx now compresses responses, as Caddy already did.
- **New installations:** `LOG_LEVEL=warning` and `CACHE_STORE=file`, saving a database round-trip on each rate-limited till request.
- **Database:** every foreign-key column now has an index (317 had none, 61 of them `tenant_id`, which nearly every query filters on). Composite indexes added for the newest-first sales, stock movement and invoice lists, plus a trigram index for customer-name search. A test (`DatabaseIndexesTest`) stops new tables from missing one.
- **Fewer queries:** price saves load units and price lists together; payroll loads departments with employees; the modifier list and the journal view no longer query once per row.
- **Till:** barcode scans and product search use an index built once per catalogue, instead of walking every product on each scan or keystroke. The search stops at the first page of results. The pay-on-bill effect now runs only when needed, not after every render.
### Added
- **Slow pages** (System → Health): every page or till call slower than `ERP_SLOW_REQUEST_MS` (1 s) is noted with its database reads, never with the query string. The Health page lists the worst of the last 7 days; kept 30 days.
- **Health checks:** *PHP code cache (OPcache)* (On or Off, from the web server), and *Slow pages (last 24 hours)*.
- **Updates back up first:** the Windows setup and `install.sh` take a backup with the installed version before replacing anything, and stop without changing anything if it fails (`-SkipBackup` / `--skip-backup` to override). Chapter 12.4 now leads with this one-step update.
- **`erp:load-test`** also times the trial balance, profit and loss, balance sheet, VAT return, product and customer search and the till catalogue on the real data, marks anything over a second *SLOW*, and shows the data size.
- **Sizing table** by business size (installation guide, Chapter 3).
### Changed (more)
- **Books within a minute:** automatic posting runs every minute (was every five).
- **Till code tidy-up:** screen files export only screens (`recordVisit` moved to `visits.ts`); clocks start from lazy initial values. 7 of 13 lint warnings left, all deliberate (load-on-open effects, today's date).
### Checked
- `composer audit` and `npm audit`: no known vulnerabilities. Type check, till tests and build clean. `php artisan optimize` and `filament:optimize` succeed on this code.

## [0.34.1] — 2026-10-13 · Industry menus, refused saves, system SKUs
### Fixed
- **Module menus follow the industry chosen at the top.** With an industry chosen, only its own menus show (Pharmacy, Restaurant, Electronics, …). With *All industries*, every installed industry's menus show. Pharmacy screens were shown on permission alone; they now need the module, like the others (`ModuleService::enabledAnywhere` follows `IndustryContext`).
- **A refused save on a custom screen** (for example switching a second industry module on for a branch under System → Modules) showed an error page. It now shows only *Not saved* with the reason, on every screen (Livewire exception hook for Filament's `Halt`).
### Added
- **Grocery menu** (shown where Grocery runs, and with Grocery or All industries chosen):
  - **Markdowns:** near-expiry markdowns, stickers and expired write-offs for the grocery stores' warehouses only;
  - **Scale items:** weighed products with scale code, SKU, unit and retail price, exportable to load the scales.
### Changed
- **SKUs are given by the system:** the industry's code and a six-digit number (`PHARMA-000123`, or `SKU-000001` for shared products). They are never typed and never change, so they cannot be duplicated.
  - The product import leaves SKU empty for new products, and keeps codes brought from a previous system so their prices and opening stock still match.
  - The service-charge and alteration settings now say to copy the SKU the system gave the product.

## [0.34.0] — 2026-10-13 · Separate books per company
### Added
- **Legal entities** (*Company → Legal entities*): the group's registered companies, with name, registration number, TIN, VAT number and address.
  - Exactly one is the head office company.
  - An entity cannot be switched off while in use.
- **Separate books per industry is the default** (owner, 2026-10-13; setting *Separate books for each industry*, on).
  - Each industry gets its own company when first switched on, named after it, plus a head office company if there is none. The owner fills in each one's TIN.
  - Switching the setting off returns all industries to the head office company's books; switching it on gives each its company again.
  - Installations already running keep one set of books (migration), because switching mid-year would split their figures.
- **Per industry:** the company can be changed under *Company → Industries → Books*. Two industries may share one company.
  - Its branches post into that company's books (`journal_lines.legal_entity_id`, backfilled).
  - Their receipts and invoices carry its name and TIN.
  - Their E-VAT submissions go under its TIN.
- **Automatic amounts owed between companies:** any entry touching two companies is balanced inside each one on the new account *1250 Due from / to group companies* (automatic, with the other company as the party). This covers expenses one company pays for another, shared head office costs and stock sent between them.
- **Finance → Reports → Books:** a filter for one company's own books (trial balance, P&L, balance sheet, ledger, profit by industry), or the whole group consolidated.
  - The VAT return is per company, for its own branches' sales.
  - The ledger check proves each company's books balance and that both sides agree on what they owe each other.
### Changed
- Goods received on a transfer clear the **sender's** stock in transit, so a transfer between industries or companies leaves nothing stranded on either side.
- Receipts fall back to the head office company, not merely the first one created.
- Docs: installation guide 8.0 (*Separate books*); accountant guide.

## [0.33.0] — 2026-10-13 · Industries (part C): accounts by industry
### Added
- **Industries as profit centres** (owner decision: one set of books first; separate books per industry later):
  - every journal line carries its branch's industry when posted (`journal_lines.industry_id`). Existing lines are tagged by a migration. Head office and shared costs stay company-level.
  - **Finance → Reports → Profit by industry:** income, cost of sales, expenses and net profit per industry, plus a *Company* column.
  - an **Industry** filter (or *Company*) on the trial balance, profit and loss, balance sheet, account ledger and budget vs actual. It starts on the industry chosen at the top.
- **Sharing head office costs between industries by sales:** off by default (owner); *System → Settings → Finance*.
  - When on, *Share head office costs* on Profit by industry posts one journal for the month, splitting each company-level cost account by the industries' sales, to the pesewa.
  - Each month is shared once; reversing the journal allows it again.
- Stock moved between industries at cost shows in each industry's stock value, through the existing transfer postings.
### Changed
- Reversals keep the original lines' industry.
- **Docs:** installation guide 8.0 (Accounts by industry); accountant guide.

## [0.32.0] — 2026-10-13 · Industries (part B): stock requests
### Added
- **Stock requests** (*Stock → Stock requests*, numbers `SRQ-`): a branch asks another for stock in its own catalogue's items.
  - Between sister branches of one industry (or head office), the supplier **sends** it (any line can be 0) or **rejects** it with a reason. Sending creates and dispatches the transfer; receiving it closes the request.
  - **Between two industries**, both sides approve first: a manager of the asking branch other than the requester, then a manager of the supplier. The supplier picks its own matching product for each item. The goods move at the sender's average cost, one base unit for one base unit, as the receiving catalogue's product, with batches of the same number and expiry.
  - The menu badge counts requests waiting on you, to send or to approve.
- **Permissions:** *Ask another branch for stock* (`stock.request`: storekeeper, supervisor, pharmacist, production, warehouse, branch and general managers) and *Approve stock requests between industries* (`stock.request.approve`: warehouse, branch and general managers). Core 0.16.0.
### Changed
- Transfers keep the industries apart. A direct transfer between branches of two industries is refused (use a request), and a branch cannot send or stock another industry's products.
- Stock requests replicate with transfers (*warehouse* group).
- **Docs:** installation guide 8.0; storekeeper and manager guides.

## [0.31.0] — 2026-10-12 · Industries (part A)
### Added
- **Industries** (owner decisions 2026-10-12): a level between the company and its branches.
  - Each branch belongs to one industry (Pharmacy, Grocery, …), and an industry has any number of branches.
  - Head office or a central warehouse may be company-level.
  - Switching an industry module on for a branch makes it a branch of that industry; the industry is created on first use, with a migration backfill for existing branches. A second industry module on the same branch is refused.
  - Sales & Distribution is an add-on that sits alongside any industry.
  - *Company → Industries* lists them (code, module, separate legal entity for later); branches have an Industry field.
- **Industry access scope:** a role granted at *Industry* covers all that industry's branches, for industry managers. Branch grants still limit a pharmacist to their own pharmacy.
- **Industry switcher** at the top of the back office (*All industries* / each industry), for people who see more than one. Every branch-filtered screen narrows to the chosen industry (`Erp::branchIds`), and products and price lists filter on it too.
- **Separate catalogues:**
  - products and price lists have an industry (default: the one chosen at the top), and existing ones are *shared* until moved;
  - a branch's till receives only its industry's products and lists (plus shared ones), with the catalogue cache key per industry.
- **Dashboards:**
  - *All industries* shows each industry's sales today and this month (*Industries*);
  - a chosen industry shows its own highlights: branches, stock value, prescriptions today and batches expiring in 90 days (Pharmacy), or batches expiring in 7 days (Grocery).
- **Only purchased and installed industries show:**
  - the set-up wizard, *System → Modules* and the industry pickers list only the modules in the licence;
  - the industry switcher and dashboards list only licensed industries that are switched on at a branch;
  - a module dropped from the licence keeps its records but its screens and fields are hidden.
- **Docs:** installation guide 8.0 (industries), 8.1 (licensed modules) and 7 (Step 4b).
### Changed
- A branch runs one industry module (the wizard test's "Food Court" no longer combines Restaurant with Grocery). The penetration-test copy now has one branch per industry (Branch A grocery, Restaurant, Pharmacy, Services), with head office company-level.

## [0.30.0] — 2026-10-12 · Cash control: vaults, Teller → Cashier → Income Controller
### Added
- **Cash control with vaults** (setting *Tills and cash → Cash control with vaults*, off by default; owner decisions 2026-10-12).
  - **Shifts:** *Morning* (07:00–14:00) and *Afternoon* (14:00–21:00) by default; they can be changed and more added (**Cash → Shifts and vaults**).
  - **Vaults:** each till has one vault (a cash tray with its own standard float) per shift.
  - **The chain:**
    - The **Cashier** issues the float and assigns the teller (**Cash → Cash desk**).
    - The **Teller** can only open the till on that vault, confirms counting the float, and closes with a count note by note and coin by coin. They are not shown the expected figure.
    - The Cashier receives and counts the tray **blind**, and hands the day to the **Income Controller** (**Cash → Income control**), who counts it blind again.
    - A count that differs from the previous person's gets one blind recount; the second count stands.
  - **Differences** are recorded against the person responsible and decided by the Income Controller: cash over/short, or charged to the staff member. A charge is recovered from pay as a staff loan of the new kind *Cash shortage*, over up to 12 months, and needs an employee record.
  - **Bank deposit:** the Income Controller records it with the slip number, as a money transfer the bank reconciliation ticks off.
  - **Separation of duties is enforced:**
    - nobody issues a vault to themselves or receives their own;
    - the Income Controller cannot receive a handover they made;
    - nobody decides their own difference.
- **Books:**
  - new accounts 1115 *Cash with cashiers* and 1125 *Cash differences awaiting approval*;
  - every move is an append-only `cash_movements` row (protected by a database trigger), posted by the auto-poster (new kind *cash*): office → till (float) → cashier → office → bank;
  - shifts on a vault no longer post their own over/short.
- **Roles:**
  - **Teller** is the former *Cashier* role, renamed by migration; existing grants keep working.
  - New **Cashier** (level 3) and **Income Controller** (level 4).
  - Eight `cash.*` permissions (Core 0.15.0), given to General Manager, Finance Manager, Branch Manager (set-up and view) and Auditor (view).
- **Till:**
  - the open-shift screen shows the vaults issued to the signed-in teller;
  - the close screen counts by notes and coins (GH₵200 to 1p), with a running total;
  - offline opens and closes carry the vault and the count.
- **Sync:** shifts and vaults travel with company set-up (down); vault sessions, handovers, differences and cash movements form the new *cash* group (both ways).
- **Docs:**
  - installation guide 7 (Step 14, cash control), 17.7 and 18.2;
  - new training guide *Cashier and Income Controller*; the quick card is now the *Teller quick card*;
  - user manual;
  - the penetration-test accounts now include a teller, a cashier and an income controller.
### Changed
- The person at the till is called **Teller** in reports, the sales list and the AI assistant (the data field is unchanged).

## [0.29.3] — 2026-10-12 · Granting roles on a licence without HR
### Fixed
- **"You can't grant Cashier here: you don't hold all of its permissions"** for the owner, on any installation licensed without HR & payroll (found on a test PC).
  - Cause: Cashier, like most built-in roles, includes HR's "request leave", which the licence switches off for everyone. The check that the granter holds each of the role's permissions therefore always failed.
  - Fix: that check now looks at the permissions the granter holds through their own roles (`Authorizer::holds`), whatever the licence switches off. What anyone can actually use is still decided by the licence.
  - The escalation rule is unchanged: someone without HR rights (e.g. IT Admin) still cannot hand out an HR role.

## [0.29.2] — 2026-10-12 · SyncLytics' licence signing key
### Added
- **SyncLytics' production signing key** (`syn-2026-1`):
  - its public half is now part of the software (`config/license.php`), so installations accept licence keys from SyncLytics;
  - until now only development keys existed, and an installer-built server could not be activated at all;
  - the private half is kept outside the repository.
### Fixed
- `license:issue --checkin=0` now issues a key without a check-in requirement, as documented (it silently became 30 days).

## [0.29.1] — 2026-10-12 · First-run fixes (found on a test PC)
### Fixed
- **A new owner could not set up two-step sign-in** ("500 Server Error" on *Set up two-factor authentication*).
  - Cause: an installation that is not yet activated is read-only, and saving the two-step secret and recovery codes was blocked like any other change. Two-step sign-in comes before the licence page, so the owner could reach neither.
  - Fix: securing one's own sign-in is now allowed before activation. It is still audited. Business data stays read-only until the licence is entered.
  - `FirstSignInTest` follows a real first sign-in: two-step set-up, then the licence page, then the setup wizard.

## [0.29.0] — 2026-10-12 · Ubuntu server: Caddy and HTTPS always
### Changed
- **The Ubuntu script (`installer/ubuntu/install.sh`) now always serves over HTTPS with Caddy** instead of nginx, which served plain http unless a domain was given. Caddy is installed from its official signed apt source; certbot is no longer used.
  - With `--domain`: a Let's Encrypt certificate, fetched and renewed automatically.
  - Without a domain: the server's own certificate authority for its IP address and host name. Devices trust it once via `http://<server>/ca.crt` (guide 4.9); the server trusts it too (`update-ca-certificates`).
  - Same rules as on Windows: only one handler per request; `.env` and other hidden files give 404; the till app's deep links fall back to its index; live updates are proxied at `/app`; the admin API is off; the `Server` header is removed.
- **Live updates** (Reverb) now listen on 127.0.0.1 only, where before they were reachable from the network on 8080. They are switched on by default, with keys created once and `REVERB_PUBLISH_*` set to the local service. They now also run in the test installation.
- **Firewall:** the script switches on `ufw` with only SSH (on its configured port), 80 and 443, and removes an old 8080 rule.
- **Updates:** re-running the script retires the old nginx site, and stops nginx if it served nothing else. It stops with a clear message if another program holds port 80 or 443. It moves `APP_URL` to https and sets `SESSION_SECURE_COOKIE=true`.
- **CI** installs over an nginx-based installation and checks:
  - nginx is retired;
  - the sign-in page answers over https;
  - `.env` gives 404;
  - the till deep link works;
  - Reverb is running and on loopback only;
  - an update run comes back up.
- **Installation guide:**
  - 5.0;
  - 5.4 (Caddy instead of nginx);
  - 5.6 (`APP_URL` is always https);
  - 5.7 (Caddy by hand), 5.8 (firewall) and 5.9 (live updates on loopback), rewritten;
  - 3, 4.9, 9.2–9.3, 12 and 18.4.
  - The browser-flag workaround is only for a server set up by hand without https.
- **Not changed:** the Docker image, which runs behind the host's own HTTPS proxy.
### Added
- **The publisher's contact details** (owner, 2026-10-12):
  - Hotline 024 563 6889; info@synclyticsenterprisesystems.com; synclyticsenterprisesystems.com.
  - The Licence page shows them next to the installation code. Licence messages that say whom to contact now read "SyncLytics (hotline …, e-mail …)" (`Branding::vendorContact()`).
  - "Powered by SES Labs" links to the website.
  - Problem reports and pilot reports go to SyncLytics support when the go-live plan names no address.
  - New `BRAND_SUPPORT_PHONE` and `BRAND_SUPPORT_EMAIL` settings. A reseller that sets `BRAND_VENDOR` gets none of SyncLytics' contacts by default.
- **Installation guide:**
  - "Who to contact" in About, with the address and office numbers;
  - 6 (licence) and 13 (getting help) show the contacts;
  - where the guide meant the company, it now says SyncLytics instead of SES Labs (chapters 1, 6, 7, 8, 9);
  - 18 lists the new settings.
- **Pentest documents:** the request for quote and the scope carry the company contacts.
### Fixed
- **A brand-new installation looped between the licence page and the setup wizard** ("redirected too many times", seen on a test PC). Before activation everyone was sent to the licence page, and before setup to the wizard, including from the licence page. The licence page is now left alone by the wizard redirect: activate first (guide chapter 6), then set up (chapter 7). Covered by `FirstSignInTest`.

## [0.28.0] — 2026-10-12 · Windows server: production web server and HTTPS
### Changed
- **The Windows installer no longer uses PHP's built-in development server over plain http on port 8010.** It installs:
  - the **Caddy** web server (version pinned, download checked against Caddy's published SHA-512 checksum);
  - four **PHP FastCGI workers**, which restart if they stop;
  - **HTTPS only**, by setup:
    - this PC only: `https://localhost`;
    - shop network: `https://<address>` and `https://<computer name>`, with the server's own certificate authority. Devices trust it once from `http://<server>/ca.crt`; the key is readable by administrators only;
    - internet name: a free Let's Encrypt certificate, fetched and renewed automatically (new optional wizard page and `-Domain` / `-Email`).
- **Only ports 80 and 443 are open to the network.**
  - Port 80 only redirects, and hands out the certificate on a shop network.
  - The PHP workers (9001–9004) and live updates (8080) listen on 127.0.0.1 only. Live updates are reached through `/app` on 443.
  - The Caddy admin API is off.
  - `.env` and other hidden files answer 404.
- **Live updates work out of the box.**
  - The installer creates the Reverb app keys.
  - The server publishes to Reverb on the same PC (new `REVERB_PUBLISH_HOST/PORT/SCHEME`).
  - Tills and shop pages are told the public https address (`broadcasting.connections.reverb.client`).
- **Running the installer again (an update)** stops the old tasks and processes, removes the old `SES Labs Web` task and the 8010/8080 firewall rule, and moves `APP_URL` to https with `SESSION_SECURE_COOKIE=true`.
- **Uninstalling** removes every task, the firewall rule and the server's certificate authority from the trusted list.
- **CI** checks the installed server:
  - it answers over https;
  - `.env` gives 404;
  - deep links into the till app work;
  - 8010 is closed and 8080 is local only;
  - a second (update) run comes back up.
- **Installation guide:**
  - 4.0 and 4.6;
  - 4.8 rewritten (Caddy by hand);
  - new 4.9 (trusting the server on PCs and Android);
  - 1, 2, 6, 7, 9.2–9.4, 13 and 18.4.
- **Other:** the browser-flag workaround (9.3) is now needed only for an Ubuntu server without a domain. The pentest scope describes the new layout.

## [0.27.0] — 2026-10-12 · Penetration test pack
### Added
- **`docs/pentest/`**: everything needed to have an outside firm test SES Labs before the first internet-facing pilot (owner decisions: test machine at the SyncLytics office, grey box, Ghana-based and international firms, certified testers).
  - Scope and rules of engagement: OWASP WSTG / ASVS level 2 / API Top 10, CVSS 3.1, forbidden actions, deliverables, free retest.
  - Request for quote: size table, tester certifications (OSCP/OSWE/CREST or equivalent), weighted evaluation.
  - Building the isolated test machine.
  - Findings handling: fix deadlines by severity; Critical and High block the pilot; contract clauses.
  - The two documents for firms are also in `docs/pentest/pdf/` (`npm run pentest`), marked Confidential.
- **`php artisan erp:pentest-setup --license=<key or file>`** fills a new, empty installation with:
  - two made-up companies, to test company isolation;
  - one sign-in per kind of role (owner, general manager, accountant, auditor, IT admin, branch manager, supervisor, cashiers), with till PINs;
  - sample data;
  - a till waiting to be paired;
  - a wide API key and a read-products-only one.
  It activates the test licence first and is all-or-nothing. Every secret goes to a private file, nothing to the screen. It refuses to run where a company already exists.
- Launch checklist line 21 and installation guide 17.9 (the independent security test); 18.5 lists the command.
### Changed
- `scripts/training.mjs` builds PDFs for any docs folder (`node scripts/training.mjs <folder> [pattern]`), with an optional footer note.

## [0.26.0] — 2026-10-31 · Product name and branding
### Changed
- The product is now called **SES Labs** — *Engineering Solutions. Empowering Businesses.* — with the owner's SES lettermark (traced as vector in `branding/product/ses-lettermark.svg`; app icon = the full SES on a white tile) (one setting: `config/brand.php` / `BRAND_NAME`; artwork in `branding/product`). The product name replaces the company name on screens, messages, installers (`C:\SESLabs`, `SESLabsDB`, `/var/www/seslabs`, Docker image `seslabs`), the desktop till ("SES Labs Till"), guides. Webhook headers are now `X-Webhook-Event`, `X-Webhook-Delivery`, `X-Webhook-Signature` (and `X-Webhook-Timestamp`, `X-Webhook-Id` for the generic REST shop). The installation guide is `SES-Labs-Installation-Guide.pdf`.
### Added
- **Product brand** files (mark, logo, dark logo, built by `branding/product/compose.py`) and `npm run brand`, which makes every favicon, home-screen icon (incl. maskable), the Windows `.ico`, the desktop till's icons and name, and the guide-cover logo from it.
- **Client branding** (Company → Branding, and a "Your brand" step in the setup wizard): logo, dark-background logo, square icon and brand colour, shown on the back office, the sign-in page of a single-company server, tills (icon, accent, receipt logo — printed as dithered black-and-white on thermal printers, cached for offline), invoices and other prints, PDF exports and the online shop. The colour is suggested from the logo, with a readability warning; a monogram (initials on the colour) stands in until an icon is uploaded. Uploads are checked and re-encoded; SVGs are cleaned and served with a locked-down content policy. Logos sync to branch servers and are in backups. Practice companies copy them.
- **Publisher**: SyncLytics is named as the company that makes and licenses SES Labs: licence messages, the installers' and desktop till's Publisher and copyright, and the guide cover (`BRAND_VENDOR` for a reseller).
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
