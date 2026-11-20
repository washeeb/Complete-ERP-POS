## New back office, release 8 part 5: accounting export and GRA E-VAT (branch `office-accounting-export`, stacked on `office-assets`)
- **Server:** `App\Domain\Office\EvatScreen` (list with counts by status, options with the mode, one report with what was sent and GRA's answer), `Office\EvatController` (index, options, show, retry through `FiscalService::submit`, batch through `FiscalService::batch`), `Office\AccountingExportController` (options with last month, the file through `AccountingExport::download`).
- **Routes:** `office-api/gra-evat`, `gra-evat/options`, POST `gra-evat/send-waiting`, `gra-evat/{report}`, POST `gra-evat/{report}/send`; `office-api/accounting-export`, `accounting-export/file`.
- **Client:** `pages/EvatPage.tsx`, `pages/EvatReportPage.tsx`, `pages/AccountingExportPage.tsx`. `/admin/gra-evat` and `/admin/accounting-export` map to the new pages.
- **Tests:** `OfficeEvatExportTest` (fake GRA). office-check opens both from the Finance menu; screenshot names now drop slashes from menu labels.
- **Not seen on screen:** one GRA report (the demo has GRA E-VAT off) and the file download (covered by the server test).
- **Next:** Finance is done in the new look. Release 8 continues with settings and admin (users and roles, settings and modules, messaging, devices, catalog and pricing setup).
