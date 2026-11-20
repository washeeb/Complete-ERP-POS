## New back office, part 7: catalogue and pricing reference lists (branch `office-catalog-setup`, stacked on `office-devices`)
- **Server:** one screen for six lists, `App\Domain\Office\ReferenceListsScreen` (`KINDS`: Resource, model, product field to move by, deletable, archivable; `FIELDS`: what each form saves), and `Office\ReferenceListsController` through `CatalogService` (`saveSimple`, `saveTaxGroup`, `savePriceList`, `moveProducts`, `mergeUom`, `deleteSimple`, `verifyTaxGroup`) and `ArchiveService`.
- **Routes:** `office-api/lists/{kind}` (GET, POST), `lists/{kind}/options`, POST `lists/{kind}/{id}`, POST `lists/{kind}/{id}/{delete|move|merge|verify|archive|restore}`.
- **Client:** one page, `pages/ReferenceListPage.tsx`, configured per kind. `/office/categories`, `/brands`, `/units`, `/tax-rates`, `/tax-groups`, `/price-lists`; the classic `/admin/categories`, `/brands`, `/uoms`, `/tax-rates`, `/tax-groups`, `/price-lists` map to them.
- **Tests:** `OfficeReferenceListsTest`. office-check opens all six from Stock and a price list's form.
- **Next:** part 8: pricing rules, promotions, prices to review, price list export, shared products.
