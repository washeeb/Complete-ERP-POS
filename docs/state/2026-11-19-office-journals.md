## New back office, release 8 part 1: journals (branch `office-journals`)
- **Release 8 plan:** the rest of Finance first — (1) journals, (2) chart of accounts, month-end and budgets, (3) bank & cash and money moved, (4) fixed assets, (5) accounting export and GRA E-VAT — then settings and admin (users and roles, settings and modules, messaging, devices, catalog and pricing setup).
- **Server:** `App\Domain\Office\JournalsScreen`, `Office\JournalsController` (index, options, show, store = New journal or Opening balances, reverse, post-now) through `JournalService` and `AutoPoster`. `ListExport` has `journals`.
- **Client:** `pages/JournalsPage.tsx` (with the journal form and account picker), `pages/JournalPage.tsx`. `/admin/journal-entries` and `/admin/journal-entries/{id}` map to `/office/journals…`, so the ledger's journal links open the new page.
- **Tests:** `OfficeJournalsTest`. office-check opens Finance → Journals and one journal.
- **Next:** release 8 part 2, chart of accounts, month-end and budgets.
