# Teller guide (till)

The person who sells at a till is the **Teller** (called *Cashier* before version 0.30). Where your shop uses vaults, the **Cashier** issues and receives the cash trays: see the *Cashier and Income Controller guide* in `docs/training`.

## Start of day

1. Open the till app. If it asks for an **enrolment code**, an administrator must register the
   device first (System → Devices) and give you the code.
2. **Sign in** with your username and PIN (on-screen keypad or keyboard). Five wrong PINs lock it
   for 15 minutes; a manager can reset it.
3. **Open shift:** count the cash in the drawer and enter it as the opening float. With vaults: choose the vault the Cashier issued to you, confirm you counted its float, and open.

## Selling

| Do this | How |
|---|---|
| Add an item | Scan its barcode, or type part of the name/SKU and press Enter (or tap the result) |
| Add a carton / pack | Scan the carton barcode, or tap **+ CTN** next to the item |
| Change quantity | Type in the Qty box and press Enter. Pieces are whole numbers; weighed units allow decimals |
| Change unit | Pick it in the Unit column |
| Remove a line | ✕ at the end of the line |
| Wholesale prices | Switch **Price list** at the top (only lists your company allows at the till) |
| Jump to search | **F2** |
| Pay | **F12** or the Pay button |

Lines shown in red have no price on the chosen list and cannot be sold on it.

## Taking payment

- **Cash:** tap a quick amount (exact, next GH₵5/10/20…) or type what the customer handed over.
  The change is shown and printed.
- **Mobile money:** choose the network (MTN MoMo, Telecel Cash, AT Money), enter the amount and the
  **transaction ID from the confirmation message**. Only accept once the customer shows the
  confirmation (the system cannot yet check MoMo by itself).
- **Card (standalone terminal):** run the card on the terminal first; enter the amount and the
  approval code from the slip.
- Mix methods as needed (e.g. part MoMo, rest cash). The sale completes when the amount due is zero.

After payment the receipt appears: **Print receipt** prints it straight away in the desktop till app with a receipt printer chosen (a browser always shows its print window first); **Show print preview** opens the print window to look at it before printing. Then **New sale**.

**A copy for a customer later:** in the back office, Sales → Till sales → the sale → **Print preview (copy)** or **Print copy**. The copy says *COPY, not the original receipt*, with who reprinted it and when, and every reprint is in the audit log.

## When the server is down

The top right shows **“Server not answering — working offline”** or **“No network”**. Keep selling:

- Sign in with your username and PIN as usual — this works offline if you have signed in on **this
  till** while it was online in the last few days (your manager sets how long).
- Sales, opening and closing shifts all work. Receipts print with their normal number and the line
  *“Recorded on this till while offline”*.
- A yellow banner counts what is **waiting to reach the server**. It goes on its own when the server is
  back. **Do not ring a sale up twice** — sending is automatic and safe.
- Closing a shift offline shows over/short worked out on the till; the server confirms it later.
- A red banner means the server **refused** something — call a supervisor. They see why in the back
  office (*Sales → Review queue*); after fixing it they press **Retry refused** on the till.
- If the till must be reinstalled before it could send everything: press **Export unsent sales** and
  give the file to your administrator.

## End of shift

**Close shift** → count all cash in the drawer (including the float) → enter it. You see the
expected amount and whether you are over or short only after entering your count. Add a note if
there is a difference. Then sign out.

## Services (salons, spas, gyms)

- Adding a **service** asks **who is doing it** — tap the person (their commission).
- **Appointments (F10):** today's bookings by staff member. **Arrived** when the customer comes in; **Check out** puts the service on the till with the right person. You can also book new appointments there.
- **Passes** (next to the customer's name): the customer's prepaid sessions. **Use for …** adds the service at no charge. If the cart has only sessions, there is nothing to pay and the sale completes at once.
- A **prepaid pack** can only be sold to a chosen customer (F4).
- Software sold with a **licence key**: the key is printed on the receipt.

## Vans (Sales & Distribution)

- The chip at the top shows the van and how far you are towards this month's target.
- **Route (F3):** today's customers in order. **Sell** (the visit is recorded when the sale is paid), **Take order** (the cart becomes an order for delivery — press **Send order**), **No order** or **Closed** (with a note). The phone adds its location if you allow it.
- Bottles and crates: the deposit is added by itself. **Empties returned** takes containers back; if the customer only brings empties, **Pay out deposit** gives them the cash.
- No signal? Keep going: sales, visits and orders are saved on the phone and sent when there is signal.
- End of day: close the shift with your cash count, then settle the van with the storekeeper.

## Light or dark

Use the **Theme** switch at the bottom left: System, Light or Dark.
