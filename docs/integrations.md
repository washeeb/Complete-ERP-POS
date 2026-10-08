# Messaging, reporting, public API and accounting exports (Phase 12)

Owner decisions (2026-10-28): SMS through **Hubtel, Arkesel, mNotify or a generic webhook** — the client chooses (they may already have one); e-mail through **any SMTP server**; **WhatsApp Business built, off until keys**; **public REST API with scoped keys**; **QuickBooks, Xero and Sage** exports.

## Messaging (`app/Domain/Messaging`)
- `Messenger::send()` logs every message in `messages` and tries it at once; failures back off 1, 5, 15, 60, then 180-minute steps, up to 8 tries (`deliverDue()` in the relay loop). No provider → status `not_configured` (visible in the log). `toCustomer()` prefers WhatsApp (provider on + template on), else SMS; plus e-mail when the e-mail template is on.
- `Gateways`: Hubtel (`smsc.hubtel.com/v1/messages/send`, basic auth), Arkesel (`sms.arkesel.com/api/v2/sms/send`, `api-key` header), mNotify (`api.mnotify.com/api/sms/quick?key=`), webhook (`{to, message, reference}`, optional HMAC `X-Signature`), WhatsApp Cloud API v20.0 template messages. Numbers normalised to 233… (`Phone`).
- `message_providers` (node-local, credentials `encrypted:array`, audit records only that they changed); `ProviderSetup` validates (sender ID 3–11 chars) and sends tests.
- `Templates`: defaults per key/channel seeded by `TenantBaseline` (missing rows only; synced in the `org` group); customer messages that a business may not want automatically (invoice, payment) start **off**. `{placeholders}`; WhatsApp parameters are the placeholders in order of appearance.
- `NotificationRelay` (outbox consumer `notify`): approvals waiting → Filament database notifications to holders of the approving permission (never the submitter); `invoice.posted` / `customer_payment.received` → customer messages when on. Booking/appointment SMS (`SmsRelay`) now go through the Messenger; the old module webhook settings remain a fallback when no SMS provider is set.
- Digital receipts: `POST /api/v1/pos/sales/{id}/send` → `ReceiptSender`; bootstrap `send_receipt` lists the channels switched on.

## Reporting (`app/Domain/Reports`)
- `ReportEngine`: 8 fixed datasets (sales lines, receipts, payments, stock levels, stock movements, invoices, supplier bills, journal lines), each with dimensions and measures (SQL fragments in code, never from users), periods, branch filter, text filters, sort, 5,000-row limit, totals row, drill-down (`run(..., $drill)`) and links to source documents. Permission per dataset; branch-scoped grants are filtered; the GL needs a company-wide grant.
- `ReportService`: saved reports (`saved_reports`), schedules daily/weekly/monthly at a local time, recipients e-mail (file attached) / WhatsApp (note) / user (bell). Runs as the owner (`erp:reports`, every 15 min, HQ only). Files kept under `storage/app/private/report-runs`.
- Excel: `Support/Exports/XlsxWriter` (no dependency; numbers as numbers, leading-zero codes as text) — "Export to Excel (.xlsx)" on every list and report page, and scheduled reports.
- Dashboard: `BusinessStats` widget (sales today vs yesterday with a 7-day spark line, month, customers owe, we owe suppliers, alerts) by permission.

## Public API (`/api/public/v1`, `app/Domain/Api`)
- `api_clients` (Sanctum tokenable): scopes (= token abilities) + **acts as** a user (`user_id`) + optional branch. Middleware `api.client:<scope>` checks scope, the run-as person's permission, and sets the tenant; 120 requests/min per client.
- Endpoints: products, product, stock, customers (list/get/create), sales, invoices (list/get), orders (create quotation → confirm through `CustomerOrderService`), webhooks (list/create/delete). Lists: `limit`, `after`, `updated_since`; answers `{data, next}`; rule errors 422 `{error, detail}`.
- `OpenApi::document()` served at `/api/public/v1/openapi.json`.
- Webhooks: outbox consumer `webhooks` → `webhook_deliveries` per subscribed endpoint; POST JSON `{id, type, created_at, data}`, headers `X-Synclytics-Event`, `X-Synclytics-Delivery` (the event id, same on retries), `X-Synclytics-Signature: t=…,v1=HMAC-SHA256("t.body", secret)`; back-off to 6 h, dead after 10 tries; an endpoint is switched off after 50 consecutive failures. Events: product.updated, customer.updated, sale.posted, invoice.posted, customer_order.confirmed, customer_order.delivered.

## Accounting export (`Domain/Finance/AccountingExport`)
Journal lines for a period: QuickBooks Online journal CSV (Journal No, Date dd/mm/yyyy, Account "code name", Debits/Credits, Location = branch), Xero manual journal CSV (signed Amount, TaxRate "Tax Exempt", tracking Branch), Sage 50 audit-trail CSV (JD/JC, nominal code, T9). Company-wide `finance.view` only; audited like every export.

## Import wizard
`SheetFile` reads CSV or Excel (.xlsx first sheet, shared/inline strings); the Import data page previews the first rows and offers "match columns" (expected column → the file's heading), passed to `ImportService::run(..., $mapping)`.

## Permissions (Core 0.13.0)
`reports.build` (3), `reports.schedule` (4), `messaging.view` (3), `messaging.templates` (4), `messaging.manage` (4, technical), `api.manage` (5).

## Deferred
Web push to browsers/phones (PWA), e-mail as HTML with the company logo, WhatsApp media (sending the report file itself), plugin SDK, Excel dates stored as serial numbers (type dates as text).
