## New back office, part 37: Today (branch `office-today`, base `office-fashion`)
- **Server:** `Office\TodayController::show` (`GET office-api/today[?yesterday=1]`): the same `OwnerToday` figures as the current page, payments as a list, and each attention item's new-look address (system health, review queue, expiry or batches) when the person may open it.
- **Client:** `TodayPage` at `/office/today` (from `/admin/today`, `?yesterday=1` kept): tabs Today / Yesterday, refreshes every minute.
- **Home screen:** `public/office.webmanifest` (start `/office/today`, scope `/office`) linked from `pos/office/index.html`, as `admin.webmanifest` is for the classic panel.
- **office-check:** opens Today from Insights and switches to Yesterday. The "current page inside" step now uses **Departments** (People → Employees → Departments), checking Recent and Favourites with it.
- **Tests:** `OfficeTodayTest` (2).
- **Still on the current pages (correcting part 36's note):** Departments and Tax and SSNIT rules (opened from Employees and Payroll, left as they were in part 21), My profile and the Setup wizard, plus the sign-in pages, which stay as they are.
