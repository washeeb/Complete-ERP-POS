# GRA E-VAT (Phase 13)

Owner decision (2026-10-29): build all three reporting modes; the developer/admin chooses per company (`evat.mode`): `realtime`, `daily`, `invoices` (default `off`).

- `FiscalService` records a `fiscal_submissions` row per document (unique per document): till sales (realtime/daily), invoices and credit notes (every mode except off; in daily mode invoices still go one by one). `FiscalRelay` (outbox consumer `fiscal`, in the relay loop) picks up committed sales (also offline ones), invoices and credit notes, and retries pending ones (back-off 1–360 min). Realtime till sales are also tried inline in `POST /pos/sales` with `GRA_EVAT_TIMEOUT` (default 8 s) so the code and QR print on the receipt (`sale.fiscal`); otherwise they follow within seconds. `erp:evat-batch` (every 15 min) sends daily-mode sales after `evat.daily_time`. GRA refusals (400/422) are `rejected` and wait for a person (Finance → GRA E-VAT → Send again).
- `FiscalAdapter` interface; `GraEvatAdapter` posts `/invoices` and `/invoices/batch` to `GRA_EVAT_URL` with `Authorization: Bearer GRA_EVAT_KEY` and `X-Taxpayer-TIN`. Tests bind `fiscal.fake`.

## Document sent

`{invoiceType: INVOICE|REFUND|CREDIT_NOTE, invoiceNumber, originalInvoiceNumber, invoiceDate, currency, exchangeRate, supplier {tin, name, branch}, buyer {tin, name, phone}, items [{code, description, quantity, unitPrice, discount, netAmount, taxes [{code: VAT|NHIL|GETFUND…, rate, amount}], totalAmount}], totals {net, taxes, total}, pricesIncludeTax}` — amounts in currency units (2 decimals). The answer is read tolerantly: reference from `reference|invoiceReference|signature|ysdcid|sdcId|invoice_number|id`, QR from `qrCode|qr_code|qr|verificationUrl`.

**Before production:** GRA issues its integration specification to onboarded taxpayers. Compare it with the payload above in GRA's sandbox and adjust `GraEvatAdapter::payload()` / the answer fields if GRA names fields differently — nothing else changes.
