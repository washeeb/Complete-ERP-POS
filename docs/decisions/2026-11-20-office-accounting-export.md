## GRA E-VAT and accounting export in the new back office
- **Sending to GRA is not wrapped in a database transaction.** It is a call to GRA's server; FiscalService already records the result, or the error and the next try, on the report itself, as the current screen does. Holding a transaction open across a slow network call would block other writers.
- **Send again refuses a report GRA already accepted** on the server (the current screen only hid the button), and needs `finance.journal`, as the current button did.
- **Send the waiting sales now is refused unless the mode is the daily batch,** as the current button was only shown then.
- **Branch scope for GRA reports.** The current list showed every branch's reports to anyone with finance.view anywhere. The new one limits a branch-scoped finance role to its branches (missing scope is not global scope). Company-wide roles see the same as before.
- **One report opens as its own page** (with an address that can be shared), instead of the current pop-up, so what was sent and what GRA answered have room.
- **The export keeps its rules in AccountingExport:** a company-wide finance role only, start not after end, and a clear message when the period has no journals. The page reuses the list download helper, so a refusal shows as a message, not a broken file.
