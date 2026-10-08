# Insights and AI (Phase 11)

Owner decisions (2026-10-27): **no cloud AI for now** (assistant off by default; local Ollama optional); if cloud is ever used, **names masked** and **personal data kept local**; copilot for **managers and up**; extras: invoice scan → draft bill, shop descriptions, cash-flow forecast, pricing advice.

## Built-in analytics (`app/Domain/Analytics`, no model needed)
| Class | What |
|---|---|
| `SalesHistory` | Daily base-unit sales per product (local day, returns netted) |
| `DemandForecaster` | 12-week history → recent level (weights 4-3-2-1) × weekday index (shrunk to 1) × payday factor (25th–3rd, measured, 0.7–1.6) × holiday / holiday-eve factors; days of cover, stock-out date, reorder qty (lead time + 7 days + 1.65·σ·√lead), suggested min/max |
| `GhanaHolidays` | Fixed statutory days, Good Friday / Easter Monday (computed), Farmers' Day (first Friday of December). Eid dates are not listed |
| `StockHealth` | Dead (no sale 90 days), overstock (>120 days cover), expiry risk per batch (FEFO, at current rate), transfer suggestions (>60 days cover → <7 days) |
| `LossPrevention` | Per cashier, 30 days: refunds, no-receipt refunds, discounts, over/short per shift, kitchen voids; leave-one-out comparison with the other cashiers; flag when ≥3 events, ≥2× peers and z ≥ 2; risk 0–100 |
| `CustomerInsights` | RFM (recency in fixed bands, frequency/spend quintiles) → segments; churn (≥3 visits, gap > 2× usual and > 30 days); basket pairs (90 days, lift); next-best offer |
| `PricingAdvisor` | Margins (default list, price before tax vs average cost; setting `insights.margin_alert_pct`), markdowns (overstock/dead), promotion uplift (during vs 28 days before) |
| `CashFlowForecaster` | Money accounts now + weekly till takings (8-week average) + customer ledger by due date − supplier ledger by due date − payroll (last run gross + employer SSNIT, at month end) − expense claims (8-week average) |
| `InsightAlerts` | Nightly (`erp:insights`, 04:30) → `ai_alerts` (stockout, expiry, margin, loss, cash); one open per fingerprint; dismissed = quiet 30 days; vanished causes → resolved. Node-local, not synced |

## AI layer (`app/Domain/Ai`)
- `AiProvider` (common message/tool format) with `OllamaProvider` (`/api/chat`, tools, images, JSON format) and `ClaudeProvider` (Messages API, tool_use/tool_result, images and PDFs).
- `AiGateway`: setting `ai.provider` = off (default) | ollama | claude. Claude needs `ANTHROPIC_API_KEY` (.env) and a monthly budget (`ai.monthly_cap_usd`, default 0 = never); prices in `config/ai.php`. `personal: true` routes to the local model when `ai.personal_data_local` is on. Every call is logged in `ai_requests` (masked prompt, answer, tools, tokens, cost, status). `AiGateway::fake()` for tests.
- `Masker`: names from tool results → `[Customer n]` / `[Staff n]`; phones and e-mails masked in free text; unmasked in the answer shown.
- `CopilotTools`: 13 fixed, parameterised, read-only reports (sales summary, stock status, reorder, demand forecast, stock health, margins, customer balances, top customers, segments, supplier payments due, cash position, cash-flow forecast, open alerts). Each needs its permission; branch-scoped grants see their branches only; company-wide money/customer tools need a company-wide grant. No SQL from the model.
- `Copilot`: tool loop (max 5 steps), system prompt "answer only from tool results"; tables returned with the answer; quick questions run a report without any model.
- `InvoiceScanner`: photo/PDF → JSON (vision model) → supplier matched by TIN then name, lines matched to goods received still to bill (SKU, supplier SKU, name similarity), the rest expense lines → `ai_documents.form` pre-fills **Enter a bill**; the person creates the bill (normal matching/approval).
- `ProductWriter`: online-shop description draft from known facts only; saved by the person through the normal product save.

## Permissions
`insights.view` (3), `insights.loss_prevention` (4), `ai.copilot` (3), `ai.usage.view` (4) — Core 0.12.0. Granted to General/Finance/Branch managers (loss prevention and usage per role), Warehouse and Production managers (insights + copilot), E-commerce manager (insights).

## Deferred
Till-side capture of line voids and no-sale drawer opens (loss prevention uses refunds, discounts, over/short and kitchen voids), competitor price import, WhatsApp/e-mail draft replies, auto-categorisation, image alt text (no product images yet), Eid holiday dates, scheduled insight e-mails (Phase 12).
