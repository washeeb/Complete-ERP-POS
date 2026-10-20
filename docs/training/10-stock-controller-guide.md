# Stock Controller guide

You look after the product list and the stock in the shop: products, stock levels, counts, shelf locations and what needs ordering. You do not change selling prices, approve your own work, sell, or handle money. Everything is in the back office (web browser) under **Catalog**, **Stock** and **Purchasing**.

## Products

- **Catalog → Products → New** to add a product; open one to correct it. Categories and brands can be added from the product form with **+**.
- **Catalog → Categories / Brands → Move products** moves everything from one to another (the way to merge two duplicates); **Delete** removes one that nothing uses.
- Prices are shown but only a manager changes them. A new product cannot be sold until a manager has given it a price.

**Why:** one clean product list means the tills, stock and reports agree.

## Adjusting stock

**Stock → Stock adjustments → New.** Choose the store and the reason, then one numbered line per product:

- **Available** is what the store holds now; **After** is what it will hold (red if it would go below zero); **Value** is the line's value at cost.
- **Details** at the end of the line holds the batch, expiry, cost, a different reason and a note. It opens by itself for medicines and other batch items.
- Under the lines you see the total value. Above the approval limit (GHS 500 unless your manager changed it) **submit** the adjustment: a manager approves and posts it. Below the limit you post it yourself.

**Why:** you see the effect of each line before saving, and large changes always get a second pair of eyes.

## Counting

**Stock → Stock counts.** Count what is on the shelf without looking at the system figure, and enter it. A manager approves and posts the count.

## Minimum and maximum stock (reorder levels)

- **Purchasing → Reorder levels**: a product's own minimum and maximum in a store (in any unit), for one store or all.
- **Purchasing → Suggested levels** (when smart levels are on): accept the levels the system worked out from sales.
- Every morning you get a notification (the bell) when products are at or below their minimum.

## Asking the buyers to order (purchase requests)

1. **Purchasing → Purchase requests → Ask the buyers to order**: the store, an optional *needed by* date, and the products and quantities. Each line shows what the store holds now.
2. The buyers are told. One of them **orders** it (a draft purchase order to a supplier, which they check and submit) or **rejects** it with the reason, which you see on the request.
3. While it waits you can **cancel** it.

**Why:** the person who sees the shelves asks; the person who negotiates and orders buys. Neither pays.

## Shelf locations (bins)

Where a store uses bins: **Stock → Bins → New** for each shelf location (e.g. A-01-02). Switching a store to bins is for the warehouse manager.

## Practise first

Use the practice company (top bar) to try an adjustment, a count and a purchase request before working on the real one.
