## New back office, release 7 part 4a: Shrink and Slow & dead stock (branch `office-stock-reports`, stacked on `office-report-builder`)
- **Release 7 part 4 is split:** 4a Shrink and Slow & dead stock (this), 4b Batches & expiry (markdowns, correct expiry, add or move a batch), 4c Returns (list, filters, return slip).
- **Server:** `App\Domain\Office\StockReportsScreen` uses the current pages' own queries (`ShrinkReport`, `SlowStock`, set up as plain objects) so both show the same; `Office\StockReportsController` (shrink, slow, exports, `move` = draft transfer). Report cells may now carry `bar` (a percentage bar).
- **Client:** `pages/ShrinkPage.tsx`, `pages/SlowStockPage.tsx`. `/admin/shrink-report` and `/admin/stock/slow-stock` map to `/office/shrink` and `/office/slow-stock` (query kept).
- **Tests:** `OfficeStockReportsTest` (the slow-stock case moves the clock forward: the stock ledger refuses back-dated rows). office-check opens both from the Stock menu.
- **Next:** release 7 part 4b, Batches & expiry.
