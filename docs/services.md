# Services & Packages

For salons, spas, gyms, clinics and consultancies. Switch the module on per branch: **System → Modules → Services & Packages**. The licence must include it.

## Services and staff

- A **service** is a product with a **duration** (Catalog → Products → **Services** → *Duration (minutes)*) and **Track stock** off. It appears in the appointment book.
- Staff who do services: **HR & payroll → Employees** → switch on **Takes appointments** and set their **Commission %**.
- A product can have its own **commission** rate (basis points; 1000 = 10 %), which beats the person's rate.

At the till, adding a service asks **who is doing it**. That person earns the commission.

## Appointments (Services → Staff calendar / Appointments, or Appointments F10 at the till)

- **Book:** the service, who does it, the date and time, the customer (or a name and phone). One person cannot be booked twice at the same time.
- **Arrived**, **No-show** (only after the start time), **Cancel** (with a reason), **Change** (time or person).
- **Check out** at the till: the service goes on the till with the person booked, and paying marks the appointment **done**.
- **Staff calendar:** the day as a grid, one column per person, one row per time step (System → Settings → Services: day start, day end, time step).
- **SMS:** a confirmation when booked (if switched on) and a reminder before the appointment (Settings → Services: hours before; 0 = never). They are sent to your own SMS gateway — the Services one, or the restaurant one if left empty.

## Prepaid packs

A **pack** is a product sold once that gives the customer sessions to use later — for example *5 massages for GHS 800, valid 90 days*.

1. Create a product with **Track stock** off and, under **Services → Prepaid pack**, one row per item: the services it can be used for and the number of sessions. Optionally the days it is valid.
2. Selling it needs a customer (the till asks for one). Each pack sold becomes a **pass** (PASS-… number) for that customer.
3. **Using a session:** with the customer chosen at the till, **Passes** → *Use for …* puts the service on the till at **no charge**; the till asks who did it. A cart with only sessions completes without payment.
4. **Returns:** a session line returned gives the session back. A pack can be returned at the till only if none of its sessions were used; otherwise a manager voids the pass (**Services → Prepaid passes → Void**) and refunds what is fair.

**Accounting:** the pack is income when it is sold, like any sale. The sessions used later are free lines. Each pass keeps its **value per session** (price before tax ÷ sessions), used as the commission base for the staff who do them.

## Memberships and subscriptions

1. **Services → Membership plans → New plan:** code, name, the product it is invoiced as (no stock; its tax group taxes the invoice), the **price per period**, how often (monthly, every 3 or 6 months, yearly) and, optionally, **sessions included each period** (empty sessions = unlimited, e.g. gym access). A plan without sessions is a plain subscription.
2. **Services → Memberships → Sign up a customer:** customer, plan, branch, start date, optional agreed price.
3. Each period is **invoiced on its first day** — the first straight away — and the invoice goes on the customer's account (**Sales → Invoices**, paid like any invoice: at the till with *Take payment*, or in the back office). When the plan includes sessions, the period's sessions become a pass valid for that period (they do not carry over).
4. **Pause** (no invoices until resumed), **Resume** (billing restarts from today), **Cancel** (no more invoices; covered to the end of the period already invoiced).

Billing runs at **head office** every night (`erp:bill-memberships`, 01:30), catching up any periods missed while the server was off. Branch servers never bill.

## Commissions (Services → Commissions)

For a period: per staff member, the lines, their value before tax and the commission; *Details* lists each line with its receipt.

- Commission = line value before tax × rate (the product's rate, else the person's). For sessions from a pass, the value is the pass's value per session.
- Returns take the commission back.
- **Payroll** pays each month's commission as the earning **Commission** (taxable). Staff paid by commission only (no basic salary) are included in the payroll of any month they earned commission.

## Software licence keys

For products such as antivirus or office software sold with a key:

1. On the product tick **Hands out a licence key** (with **Track stock** off).
2. **Services → Licence keys → Load keys:** the product, the branch that sells them, and the keys, one per line. Keys already loaded are skipped.
3. Each unit sold takes the next key; it is printed on the receipt. Online, a product with no keys left cannot be sold; offline, the sale is kept and a review item says how many keys are owed — **Hand out keys still owed** assigns them later.
4. A return marks the key **returned**; it is never handed out again. Unsold keys can be voided.

Keys belong to one branch, so two servers never hand out the same key. They are visible only to people allowed to manage them, and on the receipt.

## Roles

| Role | Can |
|---|---|
| **Stylist / Therapist** | Till; appointments (`services.appointments`) |
| **Supervisor** | Appointments; customers' passes (`services.passes`) |
| **Branch Manager, General Manager** | Also memberships (`services.memberships`), commissions (`services.commissions`) and licence keys (`services.licence_keys`) |
