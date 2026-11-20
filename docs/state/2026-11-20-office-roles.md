## New back office, settings and admin part 2: roles and access requests (branch `office-roles`, stacked on `office-users`)
- **Server:** `App\Domain\Office\RolesScreen` (roles list through `RoleResource::getEloquentQuery`, form with levels and permissions by area, one role with holders, access requests with the current status filter), `Office\RolesController` through `AccessService::saveRole`, `AccessRequests` and `ArchiveService`. The approval-aware `act()` moved to `Office\Concerns\ActsOnAccess`, used by `UsersController` too.
- **Routes:** `office-api/roles`, `roles/form`, POST `roles`, `roles/{role}` (GET and POST), POST `roles/{role}/archive|restore`, `access-requests`, POST `access-requests/{id}/approve|reject|withdraw`.
- **Client:** `pages/RolesPage.tsx`, `pages/RolePage.tsx` (also `/office/roles/new`), `pages/AccessRequestsPage.tsx`. `/admin/roles`, `/admin/roles/create`, `/admin/roles/{id}/edit` and `/admin/access-requests` map to the new pages.
- **Tests:** `OfficeRolesTest`. office-check opens Roles, one role and Access requests.
- **Next:** settings and admin part 3: company-wide access, access review, IT and support activity, staff cards.
