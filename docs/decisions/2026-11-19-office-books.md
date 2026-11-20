## Chart of accounts, month-end and budgets in the new back office
- **Same services and rules as now:** accounts through `AccountService` (codes, types, purposes, control accounts protected), archive instead of delete, closing through `PeriodService` (refused while anything is unposted or the ledger does not tie; the page shows why before you press), reopening with its own permission and a reason.
- **The budget grid scrolls sideways** with fixed column widths rather than squeezing twelve months into the screen, so account names and amounts stay readable.
- **Spread uses the server's rounding** (the same as the current page), so the months always add up to the yearly figure.
