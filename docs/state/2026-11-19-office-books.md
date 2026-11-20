## New back office, release 8 part 2: chart of accounts, month-end, budgets (branch `office-accounts`, stacked on `office-journals`)
- **Server:** `App\Domain\Office\BooksScreen` (accounts, account form with the next free code by type and by heading, periods with what blocks closing, budgets and Spread); `Office\BooksController` through `AccountService`, `ArchiveService`, `PeriodService`, `GeneralLedger::period` (set up a year) and `BudgetService`.
- **Client:** `pages/AccountsPage.tsx`, `pages/MonthEndPage.tsx`, `pages/BudgetsPage.tsx` (asks before leaving with unsaved amounts). `/admin/accounts`, `/admin/fiscal-periods` and `/admin/budgets` map to `/office/accounts`, `/office/month-end`, `/office/budgets`.
- **Permissions as now:** a finance manager closes a month, reopening needs `finance.reopen` (the general manager); an accountant sees the chart but does not change it.
- **Tests:** `OfficeBooksTest`. office-check opens all three from the Finance menu.
- **Next:** release 8 part 3, bank & cash and money moved.
