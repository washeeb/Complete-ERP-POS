## New back office, part 9: promotions (branch `office-promotions`, base `office-pricing`)
- **Server:** `App\Domain\Office\PromotionsScreen` (list through `PromotionResource::getEloquentQuery`, options, product search, one promotion as the form shows it) and `Office\PromotionsController` (save, archive/restore) through `PromotionService`, `PromotionResource::toDomain` and `ArchiveService`.
- **Routes:** `office-api/promotions` (GET, POST), `promotions/options`, `promotions/products?q=`, `promotions/{id}` (GET, POST), POST `promotions/{id}/{archive|restore}`.
- **Client:** `pages/PromotionsPage.tsx` (form in a dialog). `/admin/promotions` maps to `/office/promotions`, `/admin/promotions/create` to `?new=1`, `/admin/promotions/{id}/edit` to `?edit={id}`.
- **Tests:** `OfficePromotionsTest`. office-check opens Promotions from Stock and the New promotion form.
- **Next:** price list export (`PriceListExport`) and shared products (`SharedProductsPage`).
