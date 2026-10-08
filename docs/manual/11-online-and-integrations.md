# 11. Online stores, delivery apps and SMS

These connect your system to other services. Each one is used **only with your own account and keys** at that service. Synclytics never creates accounts for you.

**Warning:** treat every key and secret you paste here like a password. They are stored encrypted and never shown again.

## 11.1 Requirements

- A server reachable from the internet with **HTTPS** (setup D, or head office in setup E). Online stores send orders to your server; they cannot reach a PC inside a shop.
- The **scheduler** running (it sends changes every minute and checks the stores every 15 minutes).

## 11.2 Online stores (Online → Channels)

| Type | Set-up at the store | In Synclytics |
|---|---|---|
| **Built-in online shop** | none | Online Storefront module on (Chapter 8); channel type *Built-in online shop*; choose the address |
| **WooCommerce** | WooCommerce → Settings → Advanced → REST API: create a key with **Read/Write**. Webhooks: *Order created* and *Order updated* to the address Synclytics shows, with your webhook secret | Channel type *WooCommerce*: store address, key, secret, webhook secret |
| **Shopify** | Create a custom app with Admin API access to products, inventory and orders; note the access token and your location id. Webhook *Order creation* to the address Synclytics shows | Channel type *Shopify*: shop address, token, location id, webhook secret |
| **Other shops (generic)** | Must accept JSON product updates and send signed order webhooks | Channel type *Generic REST* |

For every channel:

1. **New channel**: type, name, the **fulfilling branch**, **stock warehouse** and **price list**.
2. Enter the secrets (they stay hidden; leave blank to keep the current one).
3. Copy the **webhook address** shown in the channel list into the store's webhook settings.
4. Set **safety stock** on products where needed.
5. Click **Activate**. Every product is sent once; afterwards only changes are sent.

**Why:** the store is offered *on hand − reserved − safety stock*, so the shop and the till do not sell the same last item.

**Check:** change a price; within a minute it is on the store. Place a test order on the store; it appears under **Online → Online orders** with its stock reserved.

**Tip:** **Compare with store** lists differences between the store and Synclytics and fixes them; it also runs automatically every 15 minutes.

## 11.3 Delivery apps (Restaurant)

Bolt Food, Glovo and Chowdeck orders can arrive by webhook (with the keys from **your** merchant account) or be entered by hand at the till. Set them up under **Online → Channels** with the type *Bolt Food (delivery app)*, *Glovo* or *Chowdeck*, then copy the webhook address into the app's merchant portal.

## 11.4 SMS (booking and appointment confirmations and reminders)

There is no SMS provider built in. You give the address of **your** SMS provider (or a small bridge to it) that accepts a JSON message `{"to", "message", "reference"}`:

- Restaurant bookings: **System → Settings → Restaurant → SMS gateway address (booking messages)**.
- Appointments: **System → Settings → Services → SMS gateway address for appointment messages** (leave empty to use the restaurant one).

**Check:** book with your own phone number; you receive the confirmation within a minute.
