# Promotions, loyalty, gift cards and returns

## Promotions (Pricing & tax → Promotions)

Needs `promotions.manage` (GM, marketing, e-commerce).

| Type | Example | Fields |
|---|---|---|
| Percent off | Bread 20 % off 17:00–19:00 | products / categories, percent, time window |
| Amount off | GHS 1 off each Malta | products / categories, amount per unit |
| Buy X get Y | Malta 3 for 2 (buy 2, get 1 free) | buy qty, get qty, percent off the free ones (100 % = free) |
| Bundle / mix & match | Indomie any 5 for GHS 22 | bundle qty, bundle price |
| Spend X | Spend GHS 200, save 5 % | minimum spend, percent or amount |
| Coupon | WELCOME10 | code, percent or amount, optional minimum spend |

Every promotion can be limited to dates, days of the week, a daily time window (branch time), branches and price lists.

How they combine (owner decision):
1. Each item gets the **one best** item promotion.
2. Then any manual line discount the cashier gives.
3. Then the best **spend X** offer on the basket.
4. Then **one coupon**.
5. Then a sale discount %.

The till works this out itself, so promotions work offline; the server checks the same sum online. Receipts show each promotion and "You saved".

## Discounts and price overrides on the till

On a line, **Discount** gives a percent off or a new price; **Sale discount %** applies to the whole basket. Limits by role: cashier 0 %, supervisor 10 %, branch manager 25 %, GM/owner unlimited (role limit `discount_bp`). Above the cashier's limit the till asks for a supervisor's username and PIN — this also works offline if that supervisor has signed in on the till before.

## Loyalty

- 1 point per GHS 1 spent (cash, MoMo, card, on account) by a chosen customer; points are shown on the receipt and the customer page.
- 100 points = GHS 1. Customers can pay with points from 500 points (the **Points** tender).
- Points expire 12 months after they are earned (`erp:expire-balances`, nightly at head office).
- Balances follow the customer to every branch.

Settings: `loyalty.enabled`, `loyalty.min_redeem_points`, `loyalty.expiry_months`.

## Gift cards and store credit (Sales → Gift cards & credit)

- **Sell gift card** (needs `giftcards.manage`) issues a code (GC…) for an amount paid.
- Store credit (SC…) is issued by returns without a receipt.
- At the till, pay with the **Gift card** or **Store credit** tender by scanning or typing the code.
- Balances expire 12 months after last use. Each card's history is on its row.

## Returns (till: Return, F8)

1. Scan or type the receipt number, enter the quantities coming back and a reason.
2. Within **30 days**, the refund goes back the way the customer paid (cash from the drawer, MoMo/card with a refund reference, account credit, points, gift card).
3. The refund is what was actually paid for the item, after promotions; points earned on it are taken back.
4. Without a receipt, or after 30 days: **store credit only**, with a supervisor's PIN. The till prints the store-credit code.

Returns need a connection to the server. Returned goods go back into stock.

## Taking account payments at the till

**Take payment** (customer chosen) records a cash, MoMo or card payment against the customer's account. Cash counts in the shift's expected cash.

## Checks

`php artisan erp:receivables-check` proves customer balances, points and card balances equal their ledgers.
