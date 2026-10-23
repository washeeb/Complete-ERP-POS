# 11. Online stores, delivery apps and SMS

These connect your system to other services. Each one is used **only with your own account and keys** at that service. SES Labs never creates accounts for you.

**Warning:** treat every key and secret you paste here like a password. They are stored encrypted and never shown again.

## 11.1 Requirements

- A server reachable from the internet with **HTTPS** (setup D, or head office in setup E). Online stores send orders to your server; they cannot reach a PC inside a shop.
- The **scheduler** running (it sends changes every minute and checks the stores every 15 minutes).

## 11.2 Online stores (Online → Channels)

| Type | Set-up at the store | In SES Labs |
|---|---|---|
| **Built-in online shop** | none | Online Storefront module on (Chapter 8); channel type *Built-in online shop*; choose the address |
| **WooCommerce** | WooCommerce → Settings → Advanced → REST API: create a key with **Read/Write**. Webhooks: *Order created* and *Order updated* to the address SES Labs shows, with your webhook secret | Channel type *WooCommerce*: store address, key, secret, webhook secret |
| **Shopify** | Create a custom app with Admin API access to products, inventory and orders; note the access token and your location id. Webhook *Order creation* to the address SES Labs shows | Channel type *Shopify*: shop address, token, location id, webhook secret |
| **Other shops (generic)** | Must accept JSON product updates and send signed order webhooks | Channel type *Generic REST* |

For every channel:

1. **New channel**: type, name, the **fulfilling branch**, **stock warehouse** and **price list**.
2. Enter the secrets (they stay hidden; leave blank to keep the current one).
3. Copy the **webhook address** shown in the channel list into the store's webhook settings.
4. Set **safety stock** on products where needed.
5. Click **Activate**. Every product is sent once; afterwards only changes are sent.

**Why:** the store is offered *on hand − reserved − safety stock*, so the shop and the till do not sell the same last item.

**Check:** change a price; within a minute it is on the store. Place a test order on the store; it appears under **Online → Online orders** with its stock reserved.

**Tip:** **Compare with store** lists differences between the store and SES Labs and fixes them; it also runs automatically every 15 minutes.

## 11.3 Delivery apps (Restaurant)

Bolt Food, Glovo and Chowdeck orders can arrive by webhook (with the keys from **your** merchant account) or be entered by hand at the till. Set them up under **Online → Channels** with the type *Bolt Food (delivery app)*, *Glovo* or *Chowdeck*, then copy the webhook address into the app's merchant portal.

## 11.4 SMS, WhatsApp and e-mail

Booking and appointment messages, digital receipts, invoice e-mails and scheduled reports go through **your own** SMS, WhatsApp and e-mail accounts. Setting them up is explained in **Chapter 16**.

**Note:** if you set an *SMS gateway address* under Settings → Restaurant or Services in an earlier version, it keeps working for those messages until you choose an SMS provider in Chapter 16.

## 11.5 GRA E-VAT (electronic VAT invoicing)

The system talks to GRA's **VSDC** (Virtual Sales Data Controller) the way GRA's *E-VAT API version 8.2* describes. Once GRA has onboarded your business for E-VAT (apply through GRA's E-VAT team; they first give you **staging** access to test), GRA gives you three things: the VSDC address, your **company reference** (your TIN with a suffix, e.g. `C00XXXXXXXX-001`) and your **security key**.

1. Put them in the server's `.env` (head office), then `php artisan optimize`:
   ```
   GRA_EVAT_URL=https://vsdcstaging.vat-gh.com/vsdc
   GRA_EVAT_COMPANY_REFERENCE=C00XXXXXXXX-001
   GRA_EVAT_KEY=<the security key from GRA>
   GRA_EVAT_TIN=C00XXXXXXXX
   ```
   Start with GRA's **staging** address; change to the production address GRA gives you once the tests below pass. **Separate books** (Chapter 8): each company with its own TIN has its own GRA account — add them on one line: `GRA_EVAT_ACCOUNTS={"C00YYYYYYYY": {"reference": "C00YYYYYYYY-001", "key": "…"}}`.
   **Check:** System → Health shows **GRA E-VAT: GRA's VSDC answers** once a reporting mode is chosen (step 2).
2. **System → Settings → GRA E-VAT → Report to GRA**, choose one:

| Mode | What happens | Receipts |
|---|---|---|
| **Each sale and invoice as it happens** | Every till sale and invoice is sent at once. If GRA or the internet is down, it is sent automatically later. | Carry GRA's code and QR code when GRA answers within a few seconds |
| **Till sales in a daily batch** | Till sales are sent together after the batch time (e.g. 23:30); invoices still go one by one | No GRA code |
| **Invoices and credit notes only** | Only invoices to business customers are reported | — |

3. **Finance → GRA E-VAT** lists every report: *Accepted by GRA* (with GRA's reference), *Waiting to send*, or *Refused by GRA* (with GRA's reason — fix it, e.g. the customer's TIN, then **Send again**).

**Why:** GRA requires VAT-registered businesses to report invoices electronically. The mode lets you start with invoices only, and move to real-time when your tills are reliably online.

**On the receipt:** with each sale reported as it happens, the receipt prints GRA's signature block — **SDC ID and receipt number, SDC time, internal data, signature, MRC** — and GRA's QR code, which customers can scan to check the receipt with GRA. A sale GRA has not answered within a few seconds prints *GRA E-VAT: being reported* and is sent automatically.

**Returns and credit notes** are reported to GRA as a *partial refund* of the original receipt or invoice. **Levies** go per item: NHIL, GETFund, COVID-19 (none from 1 January 2026), CST and tourism, as GRA requires.

**Note:** test with GRA's staging VSDC first: make a few sales, a return and an invoice, and compare them in Finance → GRA E-VAT with what GRA's portal shows, before switching to the production address. `docs/evat.md` describes the fields sent.

## 11.6 Mobile money that confirms itself (payment requests)

Without this, the cashier types the transaction ID from the customer's MoMo message, and a wrong or made-up ID is only found when the wallet is checked at closing. With a **payment request**, the till sends the amount to the customer's phone; the customer approves with their PIN; the sale goes through only once the payment provider confirms the money arrived, and the payment shows as **verified** with the provider's transaction ID.

**What you need:** a merchant account with a payment provider that collects from **MTN MoMo, Telecel Cash and AT Money**:

| Provider | Where | What to copy into the system |
|---|---|---|
| **Paystack** | dashboard.paystack.com → Settings → API Keys & Webhooks | The **secret key** (`sk_live_…`). Never the public key. |
| **Hubtel** | Hubtel business account → Developers / API keys; Receive Money must be enabled for your account | **API ID**, **API key** and the **POS Sales ID** (merchant account number). Ask Hubtel to allow your server's IP address for the status check. |

The provider pays the money into your bank account or wallet on its own schedule and charges its fee (about 2 %). **Why a provider:** it is the only way for the system to know, by itself, that a payment really arrived.

**Set it up (owner or finance manager):**

1. **System → Mobile money → Payment provider:** choose the provider, paste the keys, tick **Send payment requests through this provider**, save. The keys are stored encrypted on this server and never shown again.
2. Copy the **callback address** shown in the same window into the provider's dashboard (Paystack: *Webhook URL*; Hubtel: the callback is sent with each request, nothing to do). **Why:** the provider then tells the system the moment a customer pays, even after the till stopped waiting.
3. **System → Settings → Mobile money → How the till takes mobile money:**
   - *Payment request; typing the ID still allowed* — to start with;
   - *Payment request only* — once staff are used to it. Typing an ID is then possible only when the till is offline (the sale is still recorded and checked at closing).
4. Try it with a small amount on your own phone before go-live. With Paystack you can first use the test key (`sk_test_…`).

**At the till:** Pay (F12) → **Mobile money** → choose the network → type the customer's number → **Send payment request**. The customer gets a prompt on their phone and enters their PIN. The till shows *Waiting for the customer…* and adds the payment by itself when it is paid (some Telecel and AT payments send the customer a code first: type it in and **Confirm code**). If the customer does not approve within 3 minutes, send the request again or take another payment.

**System → Mobile money** lists every request: paid, not paid, timed out. **Paid, not on a sale** shows money that arrived after the till stopped waiting — ring up the sale with it, or refund the customer from the provider's dashboard.

**Branch servers:** set the provider up on each server the tills sign in to (the keys are not copied between servers).

