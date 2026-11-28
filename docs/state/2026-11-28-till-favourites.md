## Till new look, part 1b: favourite tiles per branch (branch `till-favourites`, base `till-new-look-1`)
- **Data:** `branches.till_favourites` (jsonb, ordered product ids; migration `2027_01_12_100001`), not `settings` (rewritten on every branch save with the company-setting overrides only).
- **Domain:** `OrgService::setTillFavourites` (permission `pos.settings` on the branch; active products the branch sells; max `MAX_FAVOURITES` 48; audited `branch.till_favourites_changed`).
- **Back office:** `TillFavouritesController` (`GET office-api/till-favourites[?branch]`, `GET …/products?branch&q` name/SKU/barcode, `POST …/{branch}`), branches = `Erp::branchIds('pos.settings')` (so the industry/branch picker narrows them too). Page `pages/TillFavouritesPage.tsx` (branch, SearchPicker, ordered list with up/down/remove, SaveBar). Menu entry: `App\Filament\Pages\TillFavourites` (Sales section; view `filament.pages.office-page`), `paths.ts`, `office-routes.json` regenerated.
- **Till:** `pos/catalog` sends `favourites` (in order, only products sold there); `catalogVersion` includes `branches`. `ProductTiles` puts a **Favourites** tab first and opens on it.
- **Save bar:** `usePageStyles().page` is a flex column (children do not shrink); `SaveBar` `marginTop: auto`, so it sits at the window's foot on short forms.
- **Tests:** `OfficeTillFavouritesTest` (3); till e2e favourites tab.
