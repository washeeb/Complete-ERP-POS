# Cashier guide (till)

## Start of day

1. Open the till app. If it asks for an **enrolment code**, an administrator must register the
   device first (System → Devices) and give you the code.
2. **Sign in** with your username and PIN (on-screen keypad or keyboard). Five wrong PINs lock it
   for 15 minutes; a manager can reset it.
3. **Open shift:** count the cash in the drawer and enter it as the opening float.

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

After payment the receipt appears: **Print receipt** (80 mm printer or A4), then **New sale**.

If the network drops while saving, the till keeps the sale and shows **“sale(s) saved on this till
but not yet received”** with a **Send now** button. Do **not** ring the sale up again — sending it
twice is safe, ringing it twice is not.

## End of shift

**Close shift** → count all cash in the drawer (including the float) → enter it. You see the
expected amount and whether you are over or short only after entering your count. Add a note if
there is a difference. Then sign out.
