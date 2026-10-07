# Grocery, batches and expiry dates

## Batches and expiry dates (any shop)

Tick **Batches and expiry dates** on a product (Catalog → Products) for anything with a use-by date: dairy, bread, medicines.

- **Receiving**: enter the batch number and expiry from the pack. You can do this when receiving a purchase order or on the handheld. A batch that has already expired is refused.
- **Selling**: the till always takes stock from the batch that expires first. Expired stock cannot be sold while the till is online. If a till sold it while offline, the sale is kept and flagged under Reviews.
- **Opening stock / adjustments**: give the batch. Removing stock without a batch takes the earliest expiry first.
- Transfers carry their batches to the other branch, and returns go back into the batch they were sold from.
- If you switch batches on for a product that already has stock, that stock becomes batch **OPENING**.

**Stock → Batches & expiry** shows every batch on hand by expiry date.

## Grocery module (switch on per branch: Company → Modules)

### Weighed items (scale labels)

1. Give each item weighed on the label scale its **scale code (PLU)** on the product. Sell it in **kilograms** (a unit with 3 decimals).
2. Set the scale to print EAN-13 labels: **21** · item code (5 digits) · weight in grams (5 digits) · check digit.
3. At the till, scan the label. The pack is added at its weight and today's price per kg.

Other prefixes can be set under Settings → Grocery.

### Near-expiry markdowns

**Stock → Batches & expiry → Expiring soon** suggests a markdown for batches close to their date. By default that's 30 % off at 7 days left and 50 % at 3 days (Settings → Grocery).

1. Check or change the percentage and press **Apply**.
2. **Print the stickers** and put them on the packs.
3. The till reads the sticker: it charges the sticker price and takes the stock from that batch. Promotions do not stack on stickers.

Withdraw a markdown if the stickers come off. When a batch expires, press **Write off**: it is removed as shrink (reason "Expired") and its stickers stop working.

### Shrink

**Stock → Shrink** shows stock lost in a period — damaged, expired, spoiled, stolen, count shortages — at cost, by reason and by product. Record spoiled fresh produce as an adjustment with reason **Spoilage**.

### Electronic shelf labels

If you use electronic shelf labels, enter their gateway's address under Settings → Grocery. Every price change is sent there as it happens.

Format: `POST {"items": [{"sku", "name", "price_minor", "currency", "unit"}]}`.
