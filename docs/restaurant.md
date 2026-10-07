# Restaurant, café and bar

Switch the module on per branch: **Company → Modules → Restaurant**. The licence must include it.

Bills are kept on the server, so every till, waiter tablet and kitchen screen at the branch sees the same tables. In a restaurant with patchy internet, run a branch server on the local network (see the deployment guide). Counter sales still work fully offline.

## Set-up (Restaurant menu)

- **Kitchen stations:** each place that prepares food or drink, e.g. `KITCHEN`, `BAR`, `GRILL`, per branch. Tick **Print tickets** for a station with a printer and no screen.
- **Floor plan:** each table has a number, an area (Inside, Terrace…), its seats, and its column and row on the till's floor view.
- **Modifiers:** groups of choices offered with dishes.
  - Examples: "Spice: Mild / Hot" (choose 1), "Extras: Extra cheese, No onions" (up to 2).
  - A choice can be **charged as a product** (e.g. Extra cheese at GHS 5.00). It then appears on the bill as its own line, at that product's price and tax.
- **Dishes:** on the product, under **Restaurant**, set:
  - the station that prepares it;
  - the choices offered;
  - the **recipe**: the ingredients one portion uses.

  Switch **Track stock** off for the dish itself. When the bill is paid, its ingredients come off stock. Ingredients are ordinary stocked products, such as rice by the kg or chicken portions.
- **Settings → Restaurant:**
  - **Service charge** rate (e.g. 1000 = 10 %) and its product (SKU `SERVICE` by default). Create that product with **Track stock** off; its tax group sets the tax on the service charge.
  - **Tips** on or off.

## Roles

| Role | Can |
|---|---|
| **Waiter / Bartender** | Till; open bills; take orders and send them; void items the kitchen has not started |
| **Kitchen Staff** | No till; clock in and out, leave |
| **Supervisor** | Also voids items the kitchen already has, or approves such a void with their PIN |
| **Branch Manager, General Manager** | Also set up stations, the floor plan, modifiers and recipes; see bills, tips and voids |

## At the till: table service and bar tabs

1. **Tables (F6)** shows the floor.
   - A free table shows its seats.
   - A table with a bill shows its items and how long it has been open.
   - A table with food waiting at the pass is outlined.
2. Tap a free table to open a bill. Enter the number of guests. For a **bar tab**, give the name it is opened under.
3. Find a dish or drink. For each one, choose:
   - its choices;
   - the **course** (Now, Starter, Main, Dessert);
   - the **seat**;
   - **Hold until called**, to keep it from the kitchen for now.
4. **Send to kitchen.** Items go to their station's screen at once. Held items wait until you press **Call Main** (or the course you need).
5. **Served** marks dishes as taken to the table.
6. **Void** takes items off the bill, with a reason.
   - Items still waiting can be voided by the waiter.
   - Items the kitchen already has need a supervisor's username and PIN.
   - Voids are listed under **Restaurant → Tips & voids**.
7. **Move table** moves a bill to a free table.
8. **Pay bill** pays everything left. To split by guest, tick the items (e.g. one seat's dishes) and press **Pay selected**.
   - Each dish is paid whole, with its charged extras.
   - Table and tab bills get the service charge line. The cashier can remove it.
   - On the payment screen:
     - add a **tip** (amount or 5 / 10 / 15 %);
     - use **Split between N guests** to work out each guest's share when they pay separately (one receipt, several payments).
9. When everything is paid, the table is free again.

## Counter service

Sell as usual. A dish with choices asks for them when it is added.

When the sale is paid, its dishes go to the kitchen screens as a **counter order**. The receipt shows the **order number** for collection (the last three digits).

If the till is offline, the kitchen cannot see the order. The till prints a **kitchen ticket** with the receipt instead.

## Kitchen screen

Register a device of kind **Kitchen display** (System → Devices) and enrol it with its code. A kitchen screen needs no sign-in.

- Choose its station, or "All stations".
- Tickets appear by themselves, oldest first. The border turns amber after 10 minutes and red after 20.
- Tap a dish when it is **ready** (tap again to recall it). **Collected** clears it once it has left the pass.
- At a station set to **Print tickets**, the device prints each new dish on its printer.

## Money

- **Recipes:** ingredients come off stock **when the bill is paid**. Voided items never touch stock. Returns do not put ingredients back.
- **Service charge** = rate × the bill after discounts. It is taxed through the service product.
- **Tips** are paid on top of the bill and owed to the waiter on the bill (for counter sales, to the cashier). In the books, they sit in **Tips owed to staff** (2260).
- Each month's payroll adds the person's tips to their payslip as **Tips**, which is taxable. Only people with an employee record linked to their sign-in are paid tips this way.
- **Restaurant → Tips & voids** shows tips per person and every voided item for a period.
