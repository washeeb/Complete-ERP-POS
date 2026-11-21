## New back office, part 10: price list and shared products (branch `office-catalog-tools`, base `office-promotions`)
- **Server:** `App\Domain\Office\PriceListScreen` (the classic page's query, prices, tax split, stock and cost; `rowOf` shared with the export) and `SharedProductsScreen`; `Office\CatalogToolsController` (lists, options, product search, move all, move suggested through `SharedProducts`). `ListExport` gains `price-list` (cost and margin only for `pricing.manage`).
- **Routes:** `office-api/price-list`, `price-list/options`, `price-list/products?q=`, `export/price-list`, `shared-products`, POST `shared-products/move-all`, POST `shared-products/move-suggested`.
- **Ticked products:** the Products hand-off still keeps the ids in the session (`price_list.products`) and now returns `/admin/price-list?only=1`; the new page shows only those while `only=1` is in the address ("Show all products" drops it). The classic page still pulls them on opening.
- **Client:** `pages/PriceListPage.tsx`, `pages/SharedProductsPage.tsx`; `/admin/price-list` (keeping `?only=1`) and `/admin/shared-products` map to the new pages.
- **Tests:** `OfficeCatalogToolsTest`. office-check opens both (Shared products only where the menu shows it).
- **Next:** the catalog and pricing setup is rebuilt; the next area is the owner's choice.
