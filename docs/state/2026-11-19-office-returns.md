## New back office, release 7 part 4c: Returns (branch `office-returns`, stacked on `office-batches`)
- **Server:** `App\Domain\Office\ReturnsScreen` builds on `SalesScreen::filtered` (kind = return) and adds reason, product and approval filters, the rows, the 30-day summary and the options. `Office\ReturnsController` (index, options, slip). `ReturnsReport::slipPdf()` (new) makes the slip for both back offices; the classic `slip()` calls it. `ListExport` has `returns`.
- **Client:** `pages/ReturnsPage.tsx`. `/admin/returns` maps to `/office/returns`. Back-office returns (`/admin/office-returns`) stay classic for now.
- **Tests:** `OfficeReturnsTest`. office-check opens it from the Sales menu and waits for the 30-day summary (it arrives after the list).
- **Release 7 done.** Next: release 8, settings and admin (with the rest of Finance: journals, budgets, month-end, chart of accounts, bank & cash, money moved, fixed assets, accounting exports, GRA E-VAT).
