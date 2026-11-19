## New back office, release 7 part 1: financial reports (branch `office-reports`)
- **Release 7 plan (reports):** (1) financial reports, (2) Insights, (3) report builder and saved reports, (4) stock reports (shrink, slow and dead stock, batches and expiry, returns report).
- **Server:** `App\Domain\Office\FinancialReportsScreen` turns each report of `App\Domain\Finance\FinancialReports` / `GlCheck` into tables of ready-written cells (text, link, tone), plus notes, cards and the Share head office costs proposal; `sheets()` gives the same tables to the Exporter. `Office\ReportsController`: `financial`, `financialOptions`, `financialExport`, `shareHeadOffice`. `industry=all` means every industry (absent = the industry chosen at the top).
- **Client:** `pages/FinancialReportsPage.tsx` renders the tables generically; `ExportMenu` takes a `url` as well as a list. `REBUILT` maps `/admin/financial-reports?…` to `/office/financial-reports?…` (query kept).
- **Tests:** `OfficeFinancialReportsTest`. office-check opens Finance → Reports.
- **Next:** release 7 part 2, Insights.
