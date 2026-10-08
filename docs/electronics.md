# Electronics: serial numbers, warranties, repairs and trade-ins

Switch the module on per branch: **Company → Modules → Electronics**. The licence must include it.

## Serial / IMEI numbers

On the product (Catalog → Products → **Serial numbers and warranty**):
- tick **Serial / IMEI numbers**;
- enter the **warranty** in months, counted from the date it is sold.

Serial numbers need stock tracking and cannot be combined with batches.

From then on, every unit is known by its number:

- **Receiving:** scan each unit's number. This works when receiving a purchase order in the back office or on the handheld. A number already in stock is refused.
- **Selling:** adding the product at the till asks for the number of each unit. Only numbers in stock at that till's warehouse can be sold.

  The receipt prints each number with its warranty end date. The unit is recorded against the customer.
- **Returns:** the unit comes back by the number sold.
- **Transfers:** list the numbers on the transfer line. They are "in transit" until received at the other branch.
- **Offline sales** typed without a number are kept and flagged under Reviews, so the register can be put right.
- **Stock on hand before tracking was switched on, or found in a count:** go to Electronics → Serial numbers → **Register numbers for stock on hand**. Counts change the quantity only.

**Electronics → Serial numbers** lists every unit:
- where it is, or who bought it, on which receipt;
- its warranty;
- its full history from the stock ledger.

## Repairs (Repairs F7 at the till, or Electronics → Repairs)

1. **Take in** the device. Record the customer, phone, the serial / IMEI if known, the device, what is wrong, and what was left with it.
   - A number we sold fills in the device.
   - If it is still within its warranty, the job is marked **under warranty**.
2. **Progress:** diagnosing, waiting for parts, repairing, ready to collect. It can also be **sent back to the supplier**, with their RMA number.
   - Each step is kept in the job's log with who did it.
3. **Parts and labour** are added at their price-list prices. Create a "labour" product that does not track stock. Under warranty, nothing is charged.
4. **Collection:**
   - **with charges:** "Collect and pay" puts the parts and labour on the till. Paying marks the job collected, and the parts leave stock with the sale.
   - **no charge** (warranty, or nothing done): "Hand over". Any parts used are written off to the repair as a stock adjustment.

The **Check warranty** box on the repairs screen looks up any serial number: what it is, when and to whom it was sold, and its warranty.

## Trade-ins

At payment, **Trade-in** takes a used item as part-payment:
- choose the product it goes into stock as (e.g. "Used phone");
- enter its serial / IMEI (required for serial-numbered products), its condition and the value given.

A **supervisor approves** with their username and PIN, unless the cashier holds `electronics.trade_in`.

The item goes into stock at the value given; the receipt shows the trade-in as a payment. In the books, the trade-in payment and the stock received meet in **1180 Trade-ins awaiting stock (clearing)**, which nets to zero.

## Roles

| Role | Can |
|---|---|
| **Repair Technician** | Repairs (`electronics.repairs`); till; see stock |
| **Supervisor** | Repairs, and accept or approve trade-ins (`electronics.trade_in`) |
| **Branch Manager, General Manager** | The same |
