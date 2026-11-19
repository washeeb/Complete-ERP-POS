## New back office, release 6 part 4: withholding tax (branch `office-withholding`, stacked on `office-supplier-payments`)
- **Server:** `SupplierPaymentsScreen::withholding(month)` (default last month), `whtMonths`, `whtPayments`; `SupplierPaymentsController::withholding` and `withholdingCsv` (the current page's CSV columns). Same access as Aged payables.
- **Client:** `pages/WithholdingTaxPage.tsx`. `REBUILT` maps `/admin/withholding-tax-return` to `/office/withholding-tax`.
- **office-check:** opens from the Finance menu with Supplier payments and Aged payables.
- **Tests:** added to OfficeSupplierPaymentsTest (month, due date, default month, CSV, teller 403).
- **Release 6 done.** Next: release 7, reports.
- **Seen twice:** the first `check:office` after a fresh dev-server start can miss "the Waybills page did not open inside" (a classic page loading slowly while the server warms up); a second run passes.
