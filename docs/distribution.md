# Sales & Distribution (vans, routes, reps)

Switch the module on per branch: **System → Modules → Sales & Distribution**. The licence must include it.

Owner decisions: each van is a warehouse; reps sell from a phone that works **fully offline**; credit sales **within the customer's limit**; vans are **loaded and settled every day**; routes with visits, rep targets and commission, orders for delivery, and returnable containers.

## Vans (Distribution → Vans)

- **New van:** branch, code (also the till code on receipts, e.g. `V01`), name, registration, rep, and the warehouse it is loaded from. The van is created as a warehouse with its own till.
- Register the rep's phone as a **POS till** device on the van's till (System → Devices) and enrol it (installation guide, chapter 9). The phone runs the till app: selling, receipts, MoMo, credit within limits, returns and shifts all work as in a shop, offline too.
- **Load van / Unload to warehouse:** a stock transfer between the depot and the van, dispatched and received at once (both are at the depot).

## On the rep's phone

- **Van chip** — the van code and this month's sales against the rep's target.
- **Route (F3)** — today's customers in order, with what happened at each: **Sell**, **Take order**, **No order** or **Closed** (with a note). Each visit records the phone's GPS position when the rep allows it.
  - A sale started from the route records the visit as *sold* when it is paid.
- **Take order** — the cart becomes an order for delivery from the van's home warehouse: **Send order**. It is credit-checked; an order over the limit waits on credit hold for the office. Offline, orders and visits are saved on the phone and sent when there is signal.
- **Credit sales** — pay *On account* for customers with a credit limit; offline, the phone checks the last known balance and the office reviews anything over.
- **Collections** — *Take payment* on a customer's account (online).

## Returnable containers (empties)

- Mark crates, bottles or gas cylinders as **returnable containers** (Product → Returnable containers); their price is the deposit.
- On each product sold in them, choose the container and how many go with one unit (1 per bottle; 0.042 if a crate holds 24 and you sell single bottles — part crates are rounded up).
- At the till the deposit line is added automatically. **Empties returned** takes containers back (a minus line at the deposit price); if only empties come back, the deposit is **paid out in cash**.
- **Empties held by customers** (Rep performance page) lists what each customer still holds.

## End-of-day settlement (Distribution → Vans → Start end-of-day settlement)

1. **Start** — the system lists what should be on the van, the day's sales and the rep's cash (from the closed shift).
2. **Enter counts** — what is left on the van, and how much is unloaded back to the warehouse (the rest stays for tomorrow).
3. **Submit**, then a supervisor (not the person who submitted) **approves and settles**: shortages and surpluses are booked on the van with their value; unloaded stock goes back by transfer.
4. **Settlement sheet (PDF)** — for signatures (rep, storekeeper, supervisor).

The rep closes the shift on the phone with the cash count as usual; the settlement shows expected cash, counted cash and over/short.

## Routes, targets and reports

- **Routes:** code, name, van, rep, the weekdays it runs, and the customers in visiting order.
- **Rep targets:** monthly sales target (before tax) and optional visits target.
- **Visits:** every call with outcome, note and a map link.
- **Rep performance:** sales against target, receipts, visits, strike rate (visits that sold ÷ visits), orders, collections; customers not visited on a day; empties held by customers. Export to CSV or PDF.
- **Commission:** a rep with a *Commission %* on their employee record earns it on what they sell from the van (not on deposits), paid through payroll.

## Roles

| Role | Can |
|---|---|
| **Van Sales Rep** | Sell on the van's till, collections, orders, visits |
| **Storekeeper** | Load and unload vans, count at settlement (stock transfer permission) |
| **Supervisor, Warehouse Manager** | Approve settlements (`distribution.settle`) |
| **Branch Manager, General Manager** | Also vans' routes and rep targets (`distribution.routes`) |
