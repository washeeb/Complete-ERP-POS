# Fashion & Boutique

Switch the module on per branch: **Company → Modules → Fashion**. The licence must include it.

## Styles (Fashion → Styles)

A **style** is one design in a range of sizes and colours. It has:
- a code, which becomes the start of each SKU;
- a name, category, brand and tax group;
- a **season** (e.g. SS26) and a **collection**;
- its sizes in display order (S, M, L or 38, 40, 42) and its colours;
- a price.

**Create sizes and colours** makes one product for every size × colour that does not exist yet:
- SKU `CODE-COLOUR-SIZE`, e.g. `KENTE-DR-BLACK-M`;
- name "Kente wrap dress Black M";
- an internal barcode (EAN-13 starting 20) to print on swing tags;
- the style's price on the default price list.

Each variant is an ordinary product with its own stock, price, promotions and reports. Running it again after a change updates the name, category, brand and tax of the existing variants.

- **One price for all** sets the default-list price on every size and colour. Other price lists, and any dated or bulk prices, stay as they are.
- **New sizes or colours:** add them and run Create again.
- **Sizes or colours that already have products** cannot be removed. Switch those products off instead.

The style page shows the **grid**: stock on hand for each size and colour, and what sold in the last 30 days.

**At the till**, choosing any variant of a style opens the grid with stock at that till. Tap the size and colour wanted. Scanning a swing-tag barcode adds that exact variant straight away.

## Consignment

Stock that belongs to a supplier (the **consignor**), sold on their behalf.

1. On the product, under **Consignment**, choose the consignor and their share of the price before tax, in basis points (6000 = 60 %).
2. Receive their goods as a stock adjustment with the reason **Consignment received (no cost)** and a cost of 0. Items they take back use **Consignment returned to the consignor**.
3. Each sale records what the consignor is owed; a return takes it back.
4. **Fashion → Sell-through & consignment** shows what each consignor is owed per branch, up to a date.
   - **Settle** raises one supplier bill (Payables → Supplier bills) for their share, booked to cost of sales, and marks those sales settled.
   - Approve and pay the bill as usual.

## Alterations (Alterations F9 at the till, or Fashion → Alterations)

- **Take in:** customer, phone, the garment, the work to do, the agreed charge (0 if free, e.g. with a purchase) and the ready-by date.
- **Progress:** in progress, then ready to collect, with the tailor's name.
- **Collection:**
  - **with a charge:** "Collect and pay" puts the alteration on the till at its agreed price. It is outside price lists and promotions, and taxed through the alteration product. Paying marks it collected.
  - **free:** "Hand over".
- **Set-up:** create a product with SKU `ALTERATION` (Settings → Fashion) and stock tracking off. Its tax group taxes the charge.

## Sell-through (Fashion → Sell-through & consignment)

For each style over a period, filtered by season:
- units sold (net of returns);
- on hand now;
- **sell-through** = sold ÷ (sold + on hand);
- sales before tax.

## Roles

| Role | Can |
|---|---|
| **Tailor** | Till; alterations (`fashion.alterations`) |
| **Supervisor, Branch Manager** | Alterations |
| **General Manager** | Alterations and consignment (`fashion.consignment`; settling also needs Payables) |

**Later:** a lookbook on the online shop (styles with size and colour selectors).
