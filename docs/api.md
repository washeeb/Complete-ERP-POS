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
