# Mobile money: custom gateway contract

For a provider other than Paystack or Hubtel (Flutterwave, ExpressPay, Korba, Theteller, MTN's own MoMo API, a bank's
collection API…), a developer puts a small service (the "gateway") between the system and that provider. The gateway
speaks this contract; the system needs nothing else. Set it up under **System → Mobile money → Payment provider →
Other provider (custom gateway)**: the gateway's address (https only) and a shared secret (24 characters or more).

All requests from the system carry `Authorization: Bearer <shared secret>` and JSON bodies. Amounts are in minor units
(pesewas) as `amount_minor`, also given in cedis as `amount`. Networks: `mtn`, `telecel`, `at`. Phones: international
digits, e.g. `233241234567`.

## 1. Ask for a payment — `POST {address}/charges`

```json
{ "reference": "TLX3K9Q2M7P4A1B8C", "amount_minor": 5000, "amount": 50.00, "currency": "GHS",
  "network": "mtn", "phone": "233241234567", "callback_url": "https://erp.example.com/api/v1/momo/webhook/<token>",
  "description": "Asante Pharmacy till payment" }
```

`reference` is unique per request; the same reference sent again must not prompt the customer twice.

## 2. Every answer (to 1, 3 and 4)

```json
{ "status": "pending", "transaction_id": "PROVIDER-TXN-ID", "amount_minor": 5000, "message": "Approve on your phone" }
```

`status`: `pending` (the customer is approving), `otp` (the customer received a code; the till asks for it), `success`
(the money is received — only then), `failed` (declined, cancelled or timed out; `message` says why). `amount_minor` is
what was actually paid (a success for less than asked is treated as not paid). A non-2xx answer to 1 counts as failed.

## 3. Check a payment — `GET {address}/charges/{reference}`

Called every few seconds while the till waits, after a callback, and once more when the till gives up (3 minutes).
This answer is the truth.

## 4. The customer's code — `POST {address}/charges/{reference}/otp`

```json
{ "otp": "123456" }
```

## 5. Callback — `POST {callback_url}`

When the payment's state changes, the gateway posts `{"reference": "…", "status": "…"}` to `callback_url` with header
`X-Signature: <hex HMAC-SHA256 of the raw body, keyed with the shared secret>`. The system checks the signature, then
asks the gateway (3) — so a callback alone never marks a payment as paid. Unsigned or wrongly signed callbacks get 404.

## Security

- https only; the shared secret is stored encrypted on the server and never shown again.
- Keep the provider's own keys inside the gateway, never in the till.
- Log every request in the gateway; the system logs every request under System → Mobile money.
