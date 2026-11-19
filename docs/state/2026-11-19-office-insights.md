## New back office, release 7 part 2: Insights (branch `office-insights`, stacked on `office-reports`)
- **Server:** `App\Domain\Office\InsightsScreen` (tabs as `ReportTables`, same tab rules as the classic page), `ReportsController::insights` and `insightsExport`. `ReportTables` (new) holds the table/rows/sheets helpers shared with `FinancialReportsScreen`; the controller's export is shared too.
- **Client:** `pages/InsightsPage.tsx`; `list/ReportTables.tsx` (`ReportTable`) now draws both report pages' tables. `/admin/insights?…` maps to `/office/insights?…`.
- **Tests:** `OfficeInsightsTest`. office-check opens Insights and its Stock health tab.
- **Dev preview:** until PR 222 (`server/server.php`) is merged, a preview started on a branch that has that file stops answering after switching to one that does not; restart "ERP preview".
- **Next:** release 7 part 3, report builder and saved reports.
