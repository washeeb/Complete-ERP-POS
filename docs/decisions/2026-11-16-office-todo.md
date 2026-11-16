## To-do, and everyone into the new back office (2026-11-16)
- **To-do lists the items, not counts.** People decide faster when they see the order number, who asked and the amount. The rules are the dashboard's own (`MyApprovals::KINDS`): only kinds the person may approve, where they may, never their own. So To-do can never show more than the pages behind it.
- **Till requests are answered in To-do itself.** A cashier is waiting at the till. Every other kind opens its own page, which already has the full approve or turn-down logic; that logic is rebuilt screen by screen later, not duplicated now.
- **"Confirm it's you" inside the page.** The same rule as on the Till requests page, shown in a dialog so the manager stays in To-do.
- **Signing in opens the new back office** (the owner's choice for release 1):
  - A page someone was going to (a notification's link) still opens.
  - Their chosen home (Today, a favourite) opens inside the new frame.
  - The classic back office is one click away both ways, for a few releases.
