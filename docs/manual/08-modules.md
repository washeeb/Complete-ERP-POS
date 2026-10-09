# 8. Installing and setting up modules

Modules add the features of a type of business. They are **already part of the software** — "installing" a module means three things:

1. your **licence** includes it (Chapter 6);
2. it is **switched on for each branch** that needs it;
3. its **set-up checklist** below is done.

A branch can run several modules (for example Grocery and Restaurant for a supermarket with a food court).

## 8.1 Switching a module on or off

1. **System → Modules.**
2. Choose the **Branch**, then for each module choose **Enabled** (or **Off**; a planned module offers **Reserved**).
3. Type the **Reason for change** and click **Save modules**.

![System → Modules: modules per branch](img/modules.png)

**Why:** modules are per branch so that, for example, only the pharmacy branch shows medicine screens. Every change is recorded in the audit log.

| Status shown | Meaning |
|---|---|
| **Available** | Released: can be switched on now. |
| **Planned · Phase N** | Not built yet. You may **reserve** it for a branch; it switches on by itself when that phase is released. Nothing is shown as working before it exists. |
| **Not licensed** | Your licence does not include it. Ask Ervanta. |

**Note:** switching a module **off** hides it and stops new work. It **never deletes** data; switching it on again brings everything back.

**Who can do it:** the owner and the IT Admin (permission *Manage modules*).

**Check:** after switching a module on, the module's menu appears in the back office (you may need to refresh the page), and its buttons appear on that branch's tills after their next catalog refresh (within 60 seconds, or press *Refresh* on the till).

## 8.2 Available modules and their checklists

### Online Storefront (built-in online shop)

1. Switch it on for the branch that will fulfil online orders.
2. **Online → Channels → New channel**, type *Built-in online shop*: choose the fulfilling branch, the stock warehouse, the price list and the shop's web address (`/shop/<address>`).
3. Set **safety stock** on products that sell fast in the shop too.
4. Click **Activate**.

**Why:** safety stock is held back from the shop so walk-in and offline sales do not oversell online.

**Check:** open `https://<your-domain>/shop/<address>` — your products appear with prices and availability.

### Grocery / Supermarket

1. Products with use-by dates: tick **Batches and expiry dates** (works in any shop, but essential for grocery).
2. **Weighed items:** give each its **scale code (PLU)** and sell it in **KG** (3 decimals). Set the label scale to print EAN-13 labels: `21` · item code (5 digits) · weight in grams (5 digits) · check digit.
3. **System → Settings → Grocery:** check the scale label prefixes, and the markdown rules (default 30 % off at 7 days left, 50 % at 3 days).
4. Electronic shelf labels (optional): enter their gateway address in System → Settings → Grocery.

**Why:** the till reads the weight and item from the scale label, and sells the batch expiring first, so old stock does not go to waste.

**Check:** print a test label on the scale and scan it at a till: the item appears with its weight and price.

### Pharmacy

1. On each medicine (Catalog → Products → **Medicine**): **schedule** (OTC, Prescription only, Controlled), generic name, strength, form, FDA registration number, **active ingredients**. Switch on **Batches and expiry dates** (required for medicines).
2. **Pharmacy → Drug interactions:** add the interactions you want the till to warn about.
3. **System → Settings → Pharmacy:** the Pharmacy Council premises licence number and the superintendent pharmacist, printed on the controlled-drug register.
4. Give pharmacists the **Pharmacist** role (and technicians **Pharmacy Technician**) at the branch.

**Why:** every prescription-only or controlled medicine sale needs a pharmacist's approval and records the prescription; controlled drugs go into the Controlled Drug Register.

**Check:** sell a POM medicine on a till: the till asks for the patient, the prescription and the pharmacist's PIN.

### Restaurant / Café / Bar

1. **Restaurant → Kitchen stations:** e.g. `KITCHEN`, `BAR`. Tick *Print tickets* for a station that has a printer instead of a screen.
2. **Restaurant → Floor plan:** each table with its number, area, seats and position.
3. **Restaurant → Modifiers:** groups of choices (e.g. *Spice: Mild / Hot*). A choice can be charged as a product.
4. On each **dish** (Catalog → Products → **Restaurant**): the station, the choices offered, and the **recipe** (ingredients per portion). Switch **Track stock** off for the dish itself; the ingredients are stocked products.
5. **System → Settings → Restaurant:** service charge rate and its product (create a product with SKU `SERVICE`, *Track stock* off), tips on/off, booking reminders and the SMS gateway address (only with your own SMS provider's details).
6. Give staff the **Waiter / Bartender** or **Kitchen Staff** role.
7. Set up the **kitchen screens** (Chapter 9).

**Why:** bills live on the server so every till, waiter tablet and kitchen screen shares the same tables. For a restaurant with unreliable internet, use a **branch server** (Chapter 10) so this keeps working during outages.

**Check:** open a table on a till, send a dish, and see it on the kitchen screen.

### Electronics / Technology

1. On serial-numbered products (Catalog → Products → **Serial numbers and warranty**): tick **Serial / IMEI numbers**, enter the **warranty** in months.
2. Stock already on hand: **Electronics → Serial numbers → Register numbers for stock on hand**.
3. Create a **labour** product (no stock) for repair charges.
4. Give technicians the **Repair Technician** role.

**Why:** every unit is then traced from receipt to sale, warranties are checked by number, and stolen or wrong units cannot be sold.

**Check:** add a serial-numbered product at a till: it asks for the number and refuses one that is not in stock there.

### Fashion & Boutique

1. **Fashion → Styles → New style:** code, name, tax, season, collection, sizes, colours and price. Then **Create sizes and colours** to make one product per size and colour, each with SKU and barcode.
2. **Consignment** (goods owned by a supplier): on the product, choose the consignor and their share.
3. **Alterations:** create a product with SKU `ALTERATION` and *Track stock* off (System → Settings → Fashion holds the SKU).
4. Give tailors the **Tailor** role.

**Check:** pick any variant of a style at the till: the size × colour grid opens with the stock at that till.

### Sales & Distribution (vans, routes, reps)

1. **Distribution → Vans → New van:** branch, van code (e.g. `V01`), name, registration, rep and the warehouse it is loaded from. The van is created as a warehouse **with its own till**.
2. Register the rep's phone as a **POS till** device on that van's till and enrol it (Chapter 9.4/9.5). It works **fully offline**.
3. Give the rep the **Van Sales Rep** role at the branch and a PIN; in **HR & payroll → Employees** set their **Commission %** if they earn commission.
4. **Routes:** Distribution → Routes → New route — the van, rep, weekdays and customers in visiting order.
5. **Targets:** Distribution → Rep targets — monthly sales target per rep.
6. **Returnable containers** (crates, bottles, gas cylinders): mark the container products as *returnable containers* (their price is the deposit) and choose the container on each product sold in them (Product → Returnable containers).
7. Daily: **Load van** in the morning; in the evening **Start end-of-day settlement**, count, submit, and a supervisor approves (Chapter 14 and `docs/distribution.md`).

**Why:** a van as a warehouse with its own till means every sale, receipt, shift and cash count works exactly as in a shop — offline too — and the daily settlement catches shortages the same day.

**Check:** load a van, sell from the phone, then settle: the van's stock in *Stock on hand* matches what is left on it.

### Manufacturing (factories, bakeries, workshops, food processing)

1. **Switch it on** for the branch that produces (System → Modules → Manufacturing). *Why:* the screens and work orders only work where the module is on.
2. **Work centres:** Manufacturing → Work centres → New — each machine or bench (e.g. `MIX` Mixer, `OVEN` Oven), its **cost per hour** (wages of the people there plus running costs such as power) and the hours a day it runs. *Why:* every minute worked there is charged to the product, so its cost is real; the hours show how full each machine is.
3. **Bills of materials:** Manufacturing → Bills of materials → New — the product made, how many one batch makes, the **materials** per batch (with any expected loss %), **by-products** (e.g. bran from milling), and the **operations** in order with their work centre and minutes. Then **Put in use**. *Why:* a BOM in use is locked so open orders and history stay true; to change it, start a **New revision**.
   - A material that has its own BOM is a **sub-assembly** (e.g. dough for bread): it is made on its own work order.
   - **Backflush** (default) means the material or time is charged automatically when output is reported; switch it off for items you issue by hand or time you record.
   - Tick **Quality check before stock** if output must be inspected first.
4. **Roles:** *Production Manager* (sets up, runs orders, checks quality), *Machine Operator* (runs orders), *Quality Inspector* (passes or fails output). *Why:* the person who makes the goods is not the one who passes them.
5. **Daily:** Manufacturing → **Production planning (MRP)** shows what to make and what to buy; **Raise work order**, then on the work order **Release → Report output → (Quality check) → Finish**. Print the **Job card** for the shop floor.

**Why:** production at actual cost (the owner's choice) means each product enters stock at what its materials, labour and machines really cost, and the accounts show work in progress until it is finished.

**Check:** make a small order of a product, then look at *Stock on hand*: the materials went down, the product went up, and the work order's *Actual cost* section shows the cost per unit.

### Services & Packages (salons, spas, gyms, clinics, consultancies)

1. **Services:** on each service (Catalog → Products → **Services**) enter the **duration** in minutes and switch **Track stock** off. Optionally a **commission** rate of its own.
2. **Staff:** in **HR & payroll → Employees**, switch on **Takes appointments** for each person who does services, and set their **Commission %**. Give them the **Stylist / Therapist** role (or another role with *appointments*).
3. **Prepaid packs** (e.g. *5 massages for GHS 800*): a product with *Track stock* off and, under **Services → Prepaid pack**, the services it covers and the number of sessions, and how many days it is valid.
4. **Memberships and subscriptions:** create a product to invoice them as (e.g. *Gym membership*, *Track stock* off), then **Services → Membership plans → New plan**: price per period, how often it is billed (monthly, every 3 or 6 months, yearly) and any sessions included each period.
5. **System → Settings → Services:** the calendar's start and end times and time steps, appointment reminders (hours before), and the SMS gateway address (leave empty to use the restaurant one; only with your own SMS provider).
6. **Software licence keys** (optional): on the product tick **Hands out a licence key** (*Track stock* off), then **Services → Licence keys → Load keys** for each branch that sells it.

**Why:** the duration fills the appointment book; the person who did each service earns their commission, which payroll pays each month; packs and memberships let customers prepay and use their sessions at the till.

**Check:** book an appointment in **Services → Staff calendar**, check it out at a till with **Appointments (F10)**, and see the commission under **Services → Commissions**.

**Note:** memberships are invoiced by **head office** each day (01:30) for every period that has started; the invoices go on the customer's account (Sales → Invoices) and are paid like any invoice.

## 8.3 Company-wide features (licence options)

| Feature | What it adds | Set-up |
|---|---|---|
| **Finance** | Chart of accounts, automatic postings from sales, purchases, stock and payroll; supplier bills; bank reconciliation; month-end; reports | Chapter 7, step 12; your accountant reviews the chart and opening balances |
| **HR & payroll** | Employees, clock-in at tills, leave, loans, monthly payroll with PAYE and SSNIT, payslips | Chapter 7, step 12; payroll profile checked with your accountant |

## 8.4 Planned modules

These appear as **Planned** and can be reserved: **Manufacturing** (bills of materials, production at actual cost), **Logistics & Delivery**, **Assets & Maintenance**, **Projects & Tasks**, **Helpdesk**. This guide gets a checklist for each as it is released.
