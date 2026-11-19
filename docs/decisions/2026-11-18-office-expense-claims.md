## Expense claims in the new back office
- **Same service, same rules.** New claim, Approve, Reject and Pay go through `ExpenseService` (approval limits, "never your own", the expense accounts) as on the current screen.
- **The receipt goes with the claim in one request** and is kept only if the claim is saved; a refused claim deletes the file again.
- **Pay asks to confirm it's you above the company's limit** (`Reconfirm`), inside the back office, as supplier bank details and credit notes do.
- **Release 6 is the spending side of Finance:** expense claims, supplier bills, supplier payments and aged payables, withholding tax. Journals, budgets, month-end, the chart of accounts, bank and cash, money moved, fixed assets and the exports follow with reports and settings, where they are used.
