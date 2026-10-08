# API (v1)

Base path `/api/v1`. JSON in and out. Errors: `{"error": "stable_code", "detail": "safe explanation"}`.
OpenAPI is generated in Phase 12; until then this page is the contract.

| Status | Meaning |
|---|---|
| 401 | not signed in, wrong credentials, device revoked, enrolment failed |
| 403 `scope_denied` | signed in but not allowed here |
| 422 | validation or business rule (`detail` says which) |
| 404 `not_found` | not on this device |
| 409 `conflict` | same sale id with different content, or a receipt number already used |
| 423 `pin_locked` | too many wrong PINs |
| 429 | rate limited |

## Device enrolment

`POST /devices/enroll` — no auth, 5/min per IP.

```json
{"code": "ABCD-EFGH-JKMN", "public_key": "base64 SPKI of the device's ECDSA P-256 key"}
```

`201` → `{"device_id", "token", "branch_id", "till_id"}`. Keep `token` in the device's secure store;
it identifies the device. The code works once.

## Till sign-in

`POST /pos/login` — `Authorization: Bearer <device token>`.

```json
{"username": "esi", "pin": "482613"}
```

`200` → `{"token", "expires_at", "user": {"id", "name", "username"}}`. The staff token is bound to
the device and lasts 12 hours; revoking the device ends it.

`GET /pos/me` — `Authorization: Bearer <staff token>` → `{"user": {...}, "device": {"id", "name", "branch_id", "till_id"}}`

`POST /pos/logout` — ends the staff token.

## Till (staff token)

`GET /pos/bootstrap` → company (currency, exponent, TIN/VAT), branch, till, price lists usable here,
receipt footer, open shift (expected cash hidden — blind count), `last_device_seq`, server time.

`GET /pos/catalog` → active products with sellable units (+barcodes, factor, decimals), tax components,
prices on usable lists (current and scheduled), on-hand at the till's warehouse.

`POST /pos/shifts/open` `{"opening_float_minor": 20000}` → 201 `{shift}`; 422 `shift_open` if one is open.

`POST /pos/shifts/close` `{"counted_cash_minor": 69500, "note": "…"}` → `{shift}` with expected, counted and over/short.

`POST /pos/sales` — the till builds the sale; the server recomputes and must agree exactly.

```json
{
  "id": "UUIDv7 made on the till (idempotency key)",
  "device_seq": 42,
  "occurred_at": "2026-10-06T10:15:00Z",
  "price_list_id": "uuid", "shift_id": "uuid",
  "lines": [{"product_id": "uuid", "product_uom_id": "uuid", "qty": "2", "unit_price_minor": 5800, "line_total_minor": 11600}],
  "totals": {"net_minor": 9667, "tax_minor": 1933, "total_minor": 11600},
  "payments": [
    {"method": "momo", "provider": "mtn", "amount_minor": 8000, "reference": "58123400917", "payer_phone": "0241234567"},
    {"method": "cash", "amount_minor": 3600, "tendered_minor": 5000}
  ]
}
```

→ 201 `{sale, replayed: false}` with `receipt_no` = `BRANCH-TILL-000042`. Resending the same body
→ 200 `{sale, replayed: true}` (nothing posted twice). Errors: `price_changed`, `totals_mismatch`,
`payment_mismatch`, `product_unavailable`, `no_shift`, `insufficient_stock` (only when the company
blocks negative stock), `clock_skew`, `conflict`.

Payments: cash may exceed its amount (change); MoMo needs `provider` (mtn | telecel | at) and the
transaction `reference`; card `reference` = approval code (optional). Electronic payments are stored as
`confirmed_by_cashier` until provider integration verifies them.

`GET /pos/sales/{id}` → a sale made on this device (reprint).
## Offline till queue

`POST /pos/sync` — **device** token (no staff session needed) + header `X-Device-Signature`:
base64 ECDSA P-256/SHA-256 signature (raw r‖s) of the exact request body, made with the till's enrolled key.

```json
{"events": [
  {"id": "uuid", "type": "shift_open", "grant": {"…": "offline grant"}, "payload": {"id": "same uuid", "opening_float_minor": 20000, "opened_at": "…"}},
  {"id": "sale uuid", "type": "sale", "grant": {"…": "…"}, "payload": {"…": "same as POST /pos/sales, plus catalog_version"}},
  {"id": "uuid", "type": "shift_close", "grant": {"…": "…"}, "payload": {"shift_id": "…", "counted_cash_minor": 69500, "note": null, "closed_at": "…"}}
]}
```

→ `{"results": [{"id", "status": "accepted|duplicate|rejected", "code"?, "detail"?, "receipt_no"?}]}`. Events
are applied in order, each in its own transaction (one bad event does not block the rest). `401
bad_signature` if the signature is wrong. The `offline_grant` comes from `POST /pos/login`; send it
exactly as received.

`GET /pos/catalog` sends an `ETag`; send `If-None-Match` to get `304` when nothing changed.
`POST /pos/shifts/open` accepts an optional till-generated `id`.

## Node-to-node sync (servers only)

Headers on every call: `X-Sync-Node` (caller's node id), `X-Sync-Tenant`, `X-Sync-Time` (unix seconds),
`X-Sync-Signature` = hex HMAC-SHA256(secret, time + "\n" + METHOD + "\n" + path + "\n" + sha256(body)).
Bodies and answers may be gzip (`Content-Encoding` / `Accept-Encoding`).

| Call | Purpose |
|---|---|
| `GET /sync/hello` | node id, role, company identity (used when joining) |
| `POST /sync/push` `{"events": [...]}` | caller's own events; answer `{acked_seq, results}` |
| `GET /sync/pull?after=SEQ&limit=500` | our events after SEQ for the caller's groups; `{events, last_seq, has_more}` |


## Store webhooks (Phase 4)

`POST /api/v1/channels/{channel}/webhooks` — no login; authenticated by the store's signature over the **raw body** with the channel's webhook secret. Throttled per channel.

| Store | Signature header | Delivery id header | Topic header |
|---|---|---|---|
| WooCommerce | `X-WC-Webhook-Signature` (base64 HMAC-SHA256) | `X-WC-Webhook-Delivery-ID` | `X-WC-Webhook-Topic` |
| Shopify | `X-Shopify-Hmac-Sha256` (base64 HMAC-SHA256) | `X-Shopify-Webhook-Id` | `X-Shopify-Topic` |
| Generic REST | `X-Synclytics-Signature` = hex HMAC-SHA256 of `{X-Synclytics-Timestamp}.{body}` (5-min window) | `X-Synclytics-Event-Id` | body field `topic` (default `order.created`) |

Responses: `200 {"ok":true,"status":"received"}`, `200 {"ok":true,"status":"duplicate"}` (same delivery id again), `401 {"error":"bad_signature"}`; unknown or draft channels and bodies over 2 MB are refused with the standard error body. Paused channels still store webhooks. The order is created asynchronously by `erp:relay`.

## Live updates (Phase 4)

- `GET /api/v1/pos/bootstrap` includes `realtime: {key, host, port, scheme, channels[]}` when Reverb is configured (else `null`).
- `POST /api/v1/pos/broadcasting/auth` (staff token + device) authorises private channels: `tenant.{id}.catalog`, `tenant.{id}.stock.{warehouse}`, `tenant.{id}.orders` (needs `orders.view`). Another company's channels are refused (403).
- Events (Pusher protocol, prefix the name with `.` in Echo): `catalog.changed {product_ids, event}`, `stock.changed {product_ids}`, `order.received {id, number, branch_id}`; public `storefront.{channel}`: `product.updated {id, name, price_minor, available, for_sale}`, `catalog.reload`.

## Warehouse handheld (Phase 5)

A device registered as **Handheld scanner** enrols like a till (`POST devices/enroll`, the response includes `kind`) and signs people in with `POST pos/login`. On a handheld, login needs `stock.receive`, `stock.transfer` or `stock.count` at the device's branch (not `pos.access`), returns a token with the `handheld` ability, and gives no offline grant. That token works only on `/api/v1/wms/*` (and a till token does not). Everything is limited to the device's branch; throttle `wms` is 300 requests a minute.

| Method & path | Body / query | Notes |
|---|---|---|
| GET `wms/bootstrap` | | person, device, branch, warehouses with `use_bins`, `can[]` and active bins |
| GET `wms/lookup` | `code`, `warehouse_id` | a bin label in that warehouse, or a product barcode / SKU → `{type: "bin"\|"product"}` |
| GET `wms/purchase-orders` | `warehouse_id` | approved or partly received orders with lines and their barcodes |
| POST `wms/receipts` | `purchase_order_id`, `client_id` (uuid), `supplier_ref?`, `lines[{purchase_order_line_id, qty, bin_id?}]` | → `201 {id, number, status: posted\|held}`; a repeated `client_id` returns the same GRN |
| GET `wms/bin-stock` | `bin_id` | what a bin holds |
| POST `wms/putaway` | `warehouse_id`, `product_uom_id`, `qty`, `from_bin_id`, `to_bin_id` | |
| GET `wms/transfers` | `warehouse_id` | transfers in transit to that warehouse |
| POST `wms/transfers/{id}/receive` | `lines{line_id: qty}`, `to_bin_id?`, `note?` | repeat-safe; reports `has_discrepancy` |
| GET / POST `wms/counts` | `warehouse_id` (GET); `warehouse_id`, `bin_id?` (POST starts a count) | open counts |
| POST `wms/counts/{id}/lines` | `product_uom_id`, `qty`, `bin_id?` | never returns the expected quantity (blind) |
| POST `wms/counts/{id}/submit` | | |
| POST `wms/logout` | | |

Errors use the standard body `{error, detail}`. For example: `wrong_status`, `not_on_order`, `insufficient_stock`, `own_document`, `over_limit`, `no_po`.

## Customers and credit sales on the till (Phase 6a)

- `GET pos/customers` (staff token; needs `customers.view`, otherwise it returns an empty list). Supports ETag / `If-None-Match` and returns 304 when nothing changed. Returns `{version, customers: [{id, code, name, phone, price_list_id, credit_limit_minor, credit_hold, balance_minor, available_minor, overdue_minor}]}`. Credit figures are a snapshot; online credit sales are re-checked by the server.
- `GET pos/bootstrap` `price_lists` also include lists assigned to customers (e.g. Wholesale).
- `POST pos/sales` accepts:
  - `customer_id`;
  - payment method `account` (on the customer's account);
  - online only, `credit_approval: {username, pin}` from a supervisor.

  Errors:
  - `no_customer`, `unknown_customer`;
  - `credit_needs_approval` (the till asks for a supervisor);
  - `approval_failed`, `over_limit`, `own_document`, `pin_locked`.

  Offline credit sales (queue) are accepted as they happened; going over the limit raises the review item `credit_over_limit`.

## Promotions, loyalty, gift cards and returns on the till (Phase 6b)

- `GET pos/catalog` adds `promotions` (the rows the engine needs: `type`, `product_ids`, `category_ids`, `value_bp`, `value_minor`, `buy_qty`, `get_qty`, `bundle_qty`, `min_spend_minor`, `coupon_code`, date/day/time windows, `branch_ids`, `price_list_ids`), `utc_offset_minutes` (branch time for happy hours) and product `category_id`. The catalog version changes when a promotion changes.
- `GET pos/customers` adds `points`.
- `POST pos/sales` (and queued `sale` events) accept:
  - per line `manual: {bp}` or `manual: {price_minor}` (line discount or price override), `list_total_minor`, `discount_minor`, `promotions`;
  - `coupon_code`, `sale_discount_bp`;
  - online `discount_approval: {username, pin}`; offline `discount_approved_offline_by` (the supervisor's user id from their offline sign-in);
  - payment methods `points` (needs `customer_id`; 1 point = 1 pesewa; from 500 points), `gift_card` and `store_credit` (`reference` = card code).

  Errors: `coupon_invalid`, `totals_mismatch`, `discount_needs_approval`, `points_refused`, `stored_value_refused`. Responses add `kind`, `discount_minor`, `points_earned` and per line `discount_minor`, `promotions`.
- `GET pos/receipts?receipt=HQ-T01A-000001` → `{sale, customer_id, returnable: {within_period, lines: [{id, name, qty, line_total_minor, returnable_qty, …}], tenders: [{method, paid_minor, refundable_minor, reference}]}}`; 404 `not_found`.
- `POST pos/returns` `{id, device_seq, occurred_at, shift_id, original_sale_id|null, reason, customer_id?, lines: [{original_line_id, qty}] (or product lines without a receipt), refunds: [{method, amount_minor, reference?}], approval?: {username, pin}}` → `201 {sale (kind return, negative), store_credit_code|null}`. Errors: `over_return`, `refund_mismatch`, `return_needs_approval` (no receipt or past the period: store credit with a supervisor PIN), `no_shift`. Online only.
- `POST pos/account-payments` `{customer_id, amount_minor, method: cash|momo|card, provider?, reference?}` → `201 {number, amount_minor, balance_minor}`. Counts in the current shift's cash; `no_shift` without one.

## Clocking in and out (Phase 8)

- `POST pos/clock` (device token, like `pos/login`): `{id (uuid), username, pin, kind: in|out}` → `{status: accepted|duplicate, name, kind, at}`. Wrong PINs count towards the PIN lockout (`invalid_credentials`, `pin_locked`).
- Offline: the till checks the PIN against the person's offline sign-in and queues `{type: "clock", grant, payload: {kind, occurred_at}}` in `pos/sync`; the server records it as source `offline`.

## Grocery and batches (Phase 9a)

- `GET pos/catalog` adds per product `plu` and `grocery` (null when the module is off at the till's branch): `{scale_prefixes: ["21"], markdown_prefix: "29", markdowns: [{id, code, product_id, product_uom_id, price_minor, percent_bp, valid_until}]}`.
- `POST pos/sales` lines accept `markdown_id` (a scanned sticker): unit price = the sticker price; the stock comes from its batch; promotions do not apply. Error `markdown_invalid` (withdrawn/expired, online). A sale needing expired stock online: `expired_stock`.
- Handheld `POST wms/receipts` lines accept `batch_no` and `expires_on` (required for batch-tracked products: `batch_required`); `GET wms/purchase-orders` lines carry `track_batches`.

## Pharmacy (Phase 9b)

- `GET pos/catalog` adds per product `drug` (null for non-medicines): `{schedule: otc|pom|controlled, generic_name, strength, dosage_form, active_ingredients: [..]}`. It also adds `pharmacy` (null when the module is off at the till's branch): `{interactions: [{ingredient_a, ingredient_b, severity: minor|moderate|major, note}]}`.
- `GET pos/customers` adds `allergies`, `nhis_no` and `nhis_expires_on`, but only when the signed-in person holds `pharmacy.patients` or `pharmacy.dispense`. The ETag differs too.
- `POST pos/sales`: when a POM or controlled line is sold at a pharmacy branch, the request needs:
  - `customer_id` (the patient);
  - `prescription: {prescriber_name, prescriber_reg_no?, facility?, reference?, issued_on}`;
  - online, `pharmacist_approval: {username, pin}`, unless the cashier holds `pharmacy.dispense`;
  - offline, `pharmacist_offline_by` (user id, PIN checked on the till).
- Errors (online): `prescription_required`, `pharmacist_needed`, `approval_failed`, `over_limit` (the approver lacks `pharmacy.dispense`), `pin_locked`. Offline problems become review items of type `pharmacy_unapproved`.
- Back office: `GET /print/controlled-register?warehouse=&from=&to=` (A4), `GET /print/nhis-claims.csv?from=&to=` (`pharmacy.manage`). Both are blocked when the licence is read-only.

## Restaurant (Phase 9c)

- `GET pos/catalog` adds, per product, `station` and `modifier_group_ids`. When the module is on at the till's branch, it also adds `restaurant`: `{modifier_groups: [{id, name, min, max, options: [{id, name, product_id}]}], service_charge: {product_id, bp} | null, tips}` (otherwise null).
- **Bills** (staff token, `restaurant.serve` at the branch). Each returns `{order}` with its `items` (`status`: held, fired, ready, served, void; `paid`):
  - `GET pos/restaurant/floor` → `{tables, stations, orders}` (the open bills);
  - `POST pos/restaurant/orders` `{id, kind: table|tab|counter, dining_table_id?, tab_name?, covers?, customer_id?}`; error `table_busy`;
  - `GET pos/restaurant/orders/{id}`;
  - `POST pos/restaurant/orders/{id}/items` `{items: [{id, product_id, product_uom_id, qty, modifier_option_ids, note?, course, seat?, hold}]}` (idempotent per item id); error `modifiers_invalid`;
  - `POST pos/restaurant/orders/{id}/fire` `{course?}`;
  - `POST pos/restaurant/orders/{id}/void` `{item_ids, reason, approval?: {username, pin}}`. Items the kitchen has need `restaurant.void` or an approval; error `void_needs_approval`;
  - `POST pos/restaurant/orders/{id}/serve` `{item_ids}`;
  - `POST pos/restaurant/orders/{id}/move` `{dining_table_id}`;
  - `POST pos/restaurant/orders/{id}/close`; error `bill_unpaid`.
- **Kitchen screens** (the `kds` device's own token, no sign-in):
  - `GET kds/tickets?station=CODE` → `{stations, tickets: [{order_id, number, label, kind, waiter, paid, items}]}`;
  - `POST kds/bump` `{item_ids, status: ready|fired|served}`.
- `POST pos/sales` accepts:
  - `dining_order_id`, with lines carrying `dining_item_id`. Each dish is paid whole with its charged extras. Errors `bill_changed` and `order_closed`; offline these become review items `dining_conflict`;
  - `lines.*.note`;
  - one line `service_charge: true` of the service product, priced at rate × the rest of the bill after discounts (`totals_mismatch` if it differs);
  - `tip_minor`: payments then add up to total + tip (`tip_invalid`).

  The response adds `tip_minor` and `order_number` (the bill, or the counter order created for kitchen dishes).
- **Live:** private channel `tenant.{id}.dining.{branch}`, event `dining.changed` `{order_id, event}`. Tills re-read the floor or the bill; they also poll every 10 s.

## Restaurant extras (Phase 9d)

- **Bookings** (staff token, `restaurant.serve`):
  - `GET pos/restaurant/reservations?date=YYYY-MM-DD`;
  - `POST pos/restaurant/reservations` `{guest_name, phone?, party_size, starts_at, duration_minutes?, dining_table_id?, customer_id?, note?}`; error `table_booked`;
  - `POST pos/restaurant/reservations/{id}` (change);
  - `POST .../{id}/deposit` `{method: cash|momo|card, amount_minor, provider?, reference?}`;
  - `POST .../{id}/refund` `{method, approval?}`; error `refund_needs_approval`;
  - `POST .../{id}/seat` `{dining_table_id?}` → `{order}`;
  - `POST .../{id}/no-show`;
  - `POST .../{id}/cancel` `{reason}`.

  `GET pos/restaurant/floor` adds `reservations` (today's) and `hold_minutes`. Bills include `reservation: {number, deposit_left_minor}`, `platform` and `external_ref`.
- **Delivery orders:** `POST pos/restaurant/orders` with `kind: delivery`, `platform: bolt_food|glovo|chowdeck` and `external_ref` (each once; `duplicate`).
  - Channels of type `bolt_food`, `glovo` and `chowdeck` accept the signed REST webhook (`order.created`). `lines` give `sku` (or `product_uom_id`), `qty` and `note`.
  - They push nothing to the platform.
- **Sale tenders:**
  - `deposit`: `reference` = booking number; up to the deposit left (`deposit_refused`; offline review `deposit_overdrawn`);
  - `aggregator`: `provider` = the platform, `reference` = its order number. It posts to account 1240 Due from delivery apps.
- **Waste:**
  - till: `POST pos/restaurant/waste` `{items: [{product_id, product_uom_id, qty}], reason, approval?}` → `{value_minor, currency, lines, approved_by}`; error `waste_needs_approval`;
  - kitchen screen: `GET kds/products`, and `POST kds/waste` with `username` and `pin` as well. A wrong PIN answers 401 and counts towards the lockout.
- **SMS hook:** `POST {restaurant.sms_webhook_url}` `{to, message, reference}` on booking and as a reminder (`erp:reservation-reminders`, every 5 minutes).

## Electronics (Phase 10a)

- **Catalog:** `GET pos/catalog` adds, per product, `track_serials` and `warranty_months`, and `electronics` (true when the module is on at the till's branch).
- **Serial numbers** — numbers are upper-cased with spaces and dashes removed:
  - `POST pos/sales` lines take `serials` (one per unit; required for serial-numbered products online). Errors `serial_required`, `serial_conflict` (not in stock here); offline gaps become review `serial_problem`.
  - The sale response lines carry `serials` and `warranty_until`.
  - Returns take `serials` per line; by default, the first units sold on the original line.
  - Handheld `POST wms/receipts` lines take `serials`, and `GET wms/purchase-orders` lines carry `track_serials`.
- **Trade-ins:** `POST pos/sales` takes `trade_ins: [{product_id, serial?, condition?, value_minor}]` with tender `trade_in` for their total.
  - The cashier needs `electronics.trade_in`, or `trade_in_approval: {username, pin}` from someone who has it.
  - Errors: `trade_in_needs_approval`, `trade_in_invalid`. Offline without approval → review `trade_in_unapproved`.
- **Repairs** (staff token, `electronics.repairs`):
  - `GET pos/serials/{serial}` → `{serials: [{serial, product, status, receipt_no, customer, warranty_until, in_warranty, in_stock_here}]}`;
  - `GET pos/repairs?status=open|recent`;
  - `POST pos/repairs` `{customer_name, phone?, serial?, device?, fault, accessories?}`;
  - `POST pos/repairs/{id}/status` `{status, note?, supplier_id?, rma_reference?}`;
  - `POST pos/repairs/{id}/lines` `{lines: [{product_uom_id, qty}]}` (priced from the default price list; 0 under warranty);
  - `POST pos/repairs/{id}/hand-over` (no charge only; error `payment_needed`).
  - Sale lines with `repair_job_id` collect the job (`repair_closed` if it is not open).
- **Sync:** serial changes travel with their stock movements (`stock_movement_batch.serials`); registrations without a movement go as `serial_batch`.

## Fashion (Phase 10b)

- **Catalog:** `GET pos/catalog` adds, per product, `style_id`, `size` and `colour`. It also adds `fashion` (null when the module is off at the till's branch): `{styles: [{id, code, name, sizes, colours, season, collection}], alteration_product_id}`.
- **Alterations** (staff token, `fashion.alterations`):
  - `GET pos/alterations` → `{alterations, product_id}`;
  - `POST pos/alterations` `{customer_name, phone?, garment, work, charge_minor, due_on?}`;
  - `POST pos/alterations/{id}/status` `{status: in_progress|ready, tailor?}`;
  - `POST pos/alterations/{id}/hand-over` (free only; error `payment_needed`).
- **Sales:** `POST pos/sales` lines take `alteration_id`. Such a line is the alteration product at the alteration's `charge_minor`; the engine product id is `alteration:<id>`, so it stays outside promotions. Error `alteration_invalid`; offline → review `alteration_conflict`.
- **Consignment:** each sale or return of a product with `consignor_id` writes a `consignment_sales` row (the consignor's share of the line's net). It syncs up in the `sales` group.
