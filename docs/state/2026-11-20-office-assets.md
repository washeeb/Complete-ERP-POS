## New back office, release 8 part 4: fixed assets (branch `office-assets`, stacked on `office-banking`)
- **Server:** `App\Domain\Office\AssetsScreen` (register list, one asset with its depreciation schedule, kinds of asset), `Office\AssetsController` through `AssetService` (register, dispose, saveClass). `ListExport` has `fixed-assets`.
- **Client:** `pages/FixedAssetsPage.tsx` (with Register), `pages/FixedAssetPage.tsx` (with Dispose of), `pages/AssetKindsPage.tsx`. `/admin/fixed-assets`, `/admin/fixed-assets/create`, `/admin/fixed-assets/{id}` and `/admin/asset-classes` map to the new pages.
- **Tests:** `OfficeAssetsTest`. office-check opens Fixed assets and Kinds of asset (the demo data has no asset, so one asset's page was not seen on screen).
- **Next:** release 8 part 5, accounting export and GRA E-VAT.
