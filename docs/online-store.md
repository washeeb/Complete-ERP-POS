# Online store and live updates

Phase 4. How prices and stock reach tills and online stores as they change, and how online orders come back.

## How it fits together

```
domain change ──(same transaction)──▶ outbox_events
                                         │
                       erp:relay (every minute; loops ~55 s)
             ┌───────────────┼──────────────────┬─────────────────────┐
             ▼               ▼                  ▼                     ▼
     consumer "live"   webhook_inbox        consumer "channel:{id}"  channel_jobs
     Reverb broadcast  → online orders       → product/stock jobs    → push to store (retry, dead-letter)
```

- Every change is already in the outbox (Phase 1 rule). Consumers read it with their own cursor (`outbox_cursors`, `FOR UPDATE SKIP LOCKED`), so several relays can run without double work.
- **Live messages** (Laravel Reverb, WebSockets):
  - Tills listen privately to `tenant.{id}.catalog` and `tenant.{id}.stock.{warehouse}`. Messages carry ids only; the till then re-reads its catalog through the normal API (ETag, so it is cheap).
  - The back office listens to `tenant.{id}.orders`, which needs `orders.view`.
  - Each built-in shop listens to the public channel `storefront.{channel}`, which carries public prices and availability only.
- **No socket? Still correct.** The till polls every 60 s when it is not live. The Online orders list polls every 30 s. Shop pages show current figures on every load. A failed broadcast is logged and never blocks anything.

## Channels (Online → Channels)

| Type | Pushes | Orders in | Notes |
|---|---|---|---|
| Built-in online shop | — (reads live) | checkout | Needs the **Online Storefront** module on for the branch. Address `/shop/{address}` |
| WooCommerce | products, prices, stock (REST v3) | webhook `order.created` / `order.updated` | Key + secret from WooCommerce → Settings → Advanced → REST API (read/write). HTTPS required |
| Shopify | `productSet`, `inventorySetQuantities` (GraphQL Admin 2026-07) | webhook `orders/create` | Custom-app access token + location id. Shopify order fulfilment is not sent yet |
| Generic REST | JSON POST of product state | signed webhook | For any other shop: HMAC-SHA256 of `timestamp.body`, headers `X-Synclytics-*` |
| Jumia, Glovo, Bolt Food, WhatsApp | planned | planned | Listed but cannot be activated until built |

Each channel has:
- a **fulfilling branch** and **stock warehouse**;
- a **price list**;
- a **safety stock** quantity per product. It is held back so walk-in and offline till sales do not oversell online.

The store is offered `on hand − active reservations − safety stock`, in the product's base unit and never below zero.

Setup:
1. Create the channel.
2. Enter its secrets. They are stored encrypted, never shown again, and left unchanged when the field is blank.
3. Copy the webhook address shown in the list into the store's webhook settings, with the same webhook secret.
4. Click **Activate**. Activation pushes every product once; after that only changes are sent.

**Pause** stops pushes. Order webhooks that arrive while paused are stored and turned into orders when the channel is activated again. **Compare with store** (reconciliation) lists price and stock differences and queues fixes; it also runs every 15 minutes (`erp:channels:reconcile`). **Retry failed updates** re-queues jobs that gave up.

Failures:
- Each push is retried with exponential backoff and jitter.
- After 8 attempts the job is marked failed and a *Store update failed* review item is raised.
- Webhooks are verified against the raw body, stored first (2 MB max, deduplicated by delivery id), and processed by the relay. Bad signatures get a 401 and are not stored.

## Online orders (Online → Online orders)

- An incoming order is matched to products by channel mapping, then by SKU, and repriced with the ERP's tax rules. It is then **confirmed** and its stock **reserved**.
- If the order is larger than the store may promise, it is still accepted and an *oversold* review item is raised. A store total that differs from the items (shipping, discounts) raises a review item.
- Re-sent orders are ignored. The order id is unique per channel.
- **Mark ready** → **Hand over**: records the payment (cash, MoMo, card, or already paid online), issues the stock through the ledger, and consumes the reservation. **Cancel** releases the reservation.
- Who can do what:
  - `orders.view` sees orders for their branches;
  - `orders.manage` handles them;
  - `channels.manage` sets up channels.
- Orders and reservations live at head office (cloud), where the stores connect. During a branch outage the safety stock absorbs the branch's offline till sales; they arrive by sync later. This is covered by a test.

## Built-in shop

- Pages:
  - catalogue with search and category filter, and live prices and availability;
  - order (cart);
  - checkout: name, phone, optional email and note;
  - confirmation on a signed link, valid 30 days.
- Payment is **at pickup** for now. Online payment (Paystack/Hubtel) comes with the payments work.
- Prices always come from the server; anything the browser sends is ignored.
- Protection:
  - CSRF on all forms;
  - rate limits: adding to the order 60 per minute, checkout 10 per minute;
  - a hidden honeypot field;
  - a one-time order token, so a double click places one order.
- Texts (headline, introduction, pickup note) are set on the channel; defaults are shown when blank.
- The shop address must be unique; lowercase letters, digits and dashes only.

## Running it

| Process | Command | Notes |
|---|---|---|
| WebSockets | `php artisan reverb:start` | Port 8080 by default. Behind a TLS proxy in production (`REVERB_SCHEME=https`, public `REVERB_HOST`) |
| Relay | `php artisan erp:relay` | Scheduled every minute (`schedule:run`); `--once` for a single pass |
| Reconcile | `php artisan erp:channels:reconcile` | Scheduled every 15 min |
| Shop script | `npm run build` (server) | Builds `resources/js/storefront.js` |

- `.env`:
  - `BROADCAST_CONNECTION=reverb` and `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`.
  - Generate the key and secret with `php artisan reverb:install` on each installation, and never commit them.
- The key is public: tills and shops receive it in their page or bootstrap data. The secret stays on the server.
- After upgrading, run `php artisan migrate` then `php artisan db:seed`. The seeder adds the new permissions to the standard roles.
