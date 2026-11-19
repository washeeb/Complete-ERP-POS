## New back office, release 7 part 4b: Batches & expiry (branch `office-batches`, stacked on `office-stock-reports`)
- **Server:** `App\Domain\Office\BatchesScreen` uses the current page's queries (`BatchesExpiry` as a plain object) and the same services (`MarkdownService` apply/withdraw, `InventoryService::correctBatch`, `StockAdjustmentService` for Add or move a batch). `Office\BatchesController` checks the store is one the person may see before any action.
- **Client:** `pages/BatchesPage.tsx` (Correct expiry and Add or move a batch dialogs). `/admin/batches-expiry` maps to `/office/batches`. Grocery and Pharmacy → Markdowns still open the classic page (they move with the industry pages).
- **Tests:** `OfficeBatchesTest`. office-check opens it from the Stock menu.
- **Next:** release 7 part 4c, Returns.
