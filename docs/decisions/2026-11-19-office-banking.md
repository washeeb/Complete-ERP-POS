## Bank & cash in the new back office
- **Same service, same rules:** every step through `BankingService` (permission `banking.manage`, matching rules, suggestions only when there is exactly one candidate both ways), so the classic and new pages cannot reconcile differently.
- **The reconciliation keeps its two lists side by side** with the running total of what is ticked, and each button is only on when its step can work (one statement line and an account to post; a reason to ignore), so a step is not pressed only to be refused.
- **Archiving asks for the reason** as everywhere else; it is kept in the audit log.
