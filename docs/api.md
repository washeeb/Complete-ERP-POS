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
