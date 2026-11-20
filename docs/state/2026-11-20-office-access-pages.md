## New back office, settings and admin part 3: the other People & access pages (branch `office-access-pages`, stacked on `office-roles`)
- **Server:** `App\Domain\Office\AccessPagesScreen` (company-wide access, access review, IT activity, staff cards) and `Office\AccessPagesController` (narrow, review CSV, print and stop cards) through `AccessService`, `AccessReview` and `StaffCard`.
- **Fix in `AccessService::narrow`:** IT's request is submitted before the transaction, as the new kind `access.narrow` (`AccessRequest::KINDS`, performed in `AccessRequests::perform`). **Fix in the classic `StaffCards` page:** the list is filtered by company.
- **Routes:** `office-api/company-wide-access`, POST `company-wide-access/{grant}/narrow`, `who-can-do-what`, `who-can-do-what/csv`, `it-activity`, `staff-cards`, POST `staff-cards/print|stop`.
- **Client:** `pages/CompanyWidePage.tsx`, `pages/AccessReviewPage.tsx`, `pages/ItActivityPage.tsx`, `pages/StaffCardsPage.tsx`. `/admin/company-wide-access`, `/admin/access-review`, `/admin/it-activity` and `/admin/staff-cards` map to the new pages.
- **Tests:** `OfficeAccessPagesTest`. office-check opens the four pages from People.
- **Next:** settings and admin part 4: settings and modules.
