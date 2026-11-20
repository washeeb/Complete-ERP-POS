## New back office, settings and admin part 1: users (branch `office-users`, base main)
- **Server:** `App\Domain\Office\UsersScreen` (list through `UserResource::getEloquentQuery`, options with levels up to your own, sign-in methods, card layouts, roles and places for Grant access; one user with access and name history), `Office\UsersController` through `AccessService`, `StaffCard` and `ArchiveService`. Its `act()` catches `SentForApproval` inside the transaction, so an IT Admin's request is kept and the answer is `{pending: true, message}`.
- **Routes:** `office-api/users`, `users/options`, POST `users`, `users/{user}` (GET and POST), POST `users/{user}/pin|card|card-lost|reset-two-step|archive|restore|access`, `users/{user}/access/{grant}/revoke`.
- **Client:** `pages/UsersPage.tsx`, `pages/UserPage.tsx`, `pages/UserForm.tsx` (with `useReconfirm`). `/admin/users`, `/admin/users/create` and `/admin/users/{id}/edit` map to the new pages.
- **Tests:** `OfficeUsersTest`. office-check opens People → Users and the first user.
- **Next:** settings and admin part 2: roles, access requests, company-wide access, access review, IT activity and staff cards.
