# Products, prices and stock (back office)

## Products — *Catalog → Products*

- Name, category, brand, **tax group** and **base unit**. The **SKU** (product code) is given by the system when you save, so it is never duplicated, — the unit stock
  is counted in (usually PC). The base unit cannot change once stock has moved.
- **Barcodes for the base unit:** scan or type, then Enter. A barcode belongs to one product only.
- **Other units:** e.g. *CTN, base units in one = 24*. Give each unit its own barcodes. Untick *Sold*
  for units you only buy in. A unit's size cannot change once stock has moved — add a new unit.
- **Prices** (needs pricing rights): one row per list and unit; see `docs/pricing-and-tax.md` for
  bulk and promotional prices.
- Products are never deleted (receipts reference them); untick **For sale** instead.
- Search the product list by SKU, name, or by scanning a barcode into the search box.

Reference lists: *Categories*, *Brands*, *Units of measure* (with how many decimals each allows, e.g.
KG = 3), *Pricing & tax → Price lists / Tax groups / Tax rates*.

## Stock — *Stock → Stock on hand*

- Shows each product per warehouse. **Negative stock** (filter) means more was sold than the system
  knew about — count it and correct it.
- **Opening stock / adjustment** (needs stock rights for that warehouse):
  - *Opening balance* — the first count of a product in a warehouse.
  - *Adjustment* — a signed change (−3 to remove, 5 to add) with a reason: damaged, expired,
    theft/missing, found/surplus, own use, correction.
  - Enter in any unit (e.g. 2 CTN = 48 PC).
- *Stock → Stock ledger* lists every movement — openings, adjustments, sales — with who, when and why.
  Nothing is overwritten: a mistake is fixed with a correcting adjustment.
- Setting: *System → Settings → Stop sales when the system shows no stock* (off by default).

## Sales — *Sales → Till sales / Till shifts*

- Every till sale with its receipt number, items, tax and payments (read-only).
- Shifts show float, expected and counted cash, and over/short per till.

Health checks (IT): `php artisan erp:stock-check` proves stock levels equal the ledger.
