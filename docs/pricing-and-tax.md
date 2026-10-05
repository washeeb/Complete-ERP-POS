# Prices, tax and money

> **Accountant check required before go-live.** The starter Ghana tax set-up (VAT 15%, NHIL 2.5%,
> GETFund 2.5%, all charged on the pre-tax price) was entered at build time and is marked
> **Not verified**. A qualified accountant must confirm the rates and the method below against
> current GRA rules, then record it under *Pricing & tax → Tax groups → Record accountant check*.
> Changing a group's taxes clears its check.

## Money and quantities

- Money is stored as whole **minor units** (pesewas) with its currency. No decimals, no floats.
- Quantities are stored with 3 decimals and calculated as whole thousandths.
- Rounding is **half away from zero** to the minor unit (GH₵0.005 → GH₵0.01).
- The till and the server use the same rules (`pos/src/money.ts` = `server/app/Support/Qty.php` +
  `TaxCalculator.php`, tested with identical cases). The server recalculates every sale and refuses
  one that differs by even a pesewa.

## Price lists

- A company starts with **Retail** (default) and **Wholesale**. Both are tax-inclusive.
- Each price is for one **product unit** on one **list** (piece, carton…). A carton has its own price;
  it is never derived from the piece price.
- **Bulk prices:** add another row with a *From qty* (e.g. 10+ at GH₵18.50). The highest tier the
  quantity reaches wins.
- **Promotions:** add a row with *From / Until* dates. While active it beats the normal price for the
  same tier; the bulk tier still wins if the customer buys enough.
- No price on the chosen list = not for sale on that list (the till says so). There is no automatic
  fallback to another list.
- *Cashier can switch to it at the till*: the cashier may pick that list for a sale. Customer-specific
  and credit price lists arrive with customers (Phase 6).
- Whether a list's prices include tax cannot change once it has prices — make a new list instead.

## Tax calculation (per sale line)

1. Line total = quantity × unit price, rounded.
2. **Tax-inclusive list:** pre-tax = line total × 10000 ÷ (10000 + sum of the rates in basis points),
   rounded; tax = line total − pre-tax. The tax is split between the components in proportion to
   their rates; any pesewa left over goes to the component with the largest remainder (the earlier
   one on a tie).
3. **Tax-exclusive list:** each component = pre-tax × rate, rounded; total = pre-tax + components.
4. No tax on tax. Sale totals are the sums of the lines.

Example (inclusive, 20% combined): GH₵10.00 → pre-tax 8.33, VAT 1.25, NHIL 0.21, GETFund 0.21.

Posted sales keep the exact tax they were charged; changing a rate later only affects new sales.
