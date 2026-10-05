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