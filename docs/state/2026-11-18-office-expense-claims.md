## New back office, release 6 part 1: expense claims (branch `office-expense-claims`)
- **Release 6 plan:** (1) expense claims, (2) supplier bills, (3) supplier payments and aged payables, (4) withholding tax. The rest of Finance (journals, budgets, month-end, chart of accounts, bank & cash, money moved, fixed assets, accounting exports, GRA E-VAT) goes with the later releases (reports, settings and admin).
- **Server:** `App\Domain\Office\ExpenseClaimsScreen` (`filtered` / `sorted` / `rowOf` on `ExpenseClaimResource::getEloquentQuery`: q on number, description, claimant, payee; status; mine; cards waiting (not one's own, for approvers) and to pay; `show` with approve / reject (not one's own) and pay (money accounts, petty cash by default); `form`). `Office\ExpenseClaimsController`: index, options, show, form, save (multipart with the receipt; the file is deleted if the claim is refused), `action` (approve, reject, pay; `ReconfirmNeeded` → 428). `ListExport` gains `expense-claims`.
- **Client:** `pages/ExpenseClaimsPage.tsx`, `ExpenseClaimPage.tsx` (Pay opens Confirm it's you when asked), `ExpenseClaimFormPage.tsx`. `REBUILT` maps `/admin/expense-claims`, `/create`, `/{uuid}`.
- **office-check:** the list, a claim (when there is one), and the New claim form (Submit off while empty).
- **Tests:** OfficeExpenseClaimsTest (3).
- **Next:** release 6 part 2, supplier bills.
