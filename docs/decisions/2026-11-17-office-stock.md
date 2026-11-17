## Stock on hand and the stock ledger in the new back office
- **The current screens' own queries.** `StockScreen` starts from `StockLevelResource::getEloquentQuery()` and `StockMovementResource::getEloquentQuery()`, not copies of them. Which warehouses and industries a person sees therefore cannot drift between the two back offices.
- **Search also finds a scanned barcode** (exact match), as the products list does. The current Stock on hand searches only SKU and name.
- **The adjustment form finds products as you type** (SKU, name or barcode, products that track stock in the person's industries) and offers the product's units. The service (`InventoryService::adjust`) checks `stock.adjust` for the warehouse, as before.
- **The stock sheet is the same code**, moved from the current page's action into the controller, so the PDF and CSV are identical.
- **Found: no export on the rebuilt lists.** Every current table gets Export (Excel, CSV, PDF) from `TableExport::group()`. The rebuilt lists of releases 2 and 3 have none. Decided: add one shared export to all rebuilt lists in its own change, rather than piecemeal.
