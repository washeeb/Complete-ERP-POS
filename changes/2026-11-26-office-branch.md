### Added
- **One industry and branch picker at the top of the back office** (free; replaces the industry box): it shows where you are, e.g. *Grocery › All branches*. Industries on the left, the chosen industry's branches on the right (all of them, or one), a search, and the places used lately. An industry where the person has one branch needs no branch choice. Shown to anyone whose access covers two or more branches, with only the industries and branches they cover.
- With a place chosen, every list, report, the Business Portal and To-do show only it, and new documents start with its store filled in.
- **The choice is remembered for the person** and comes back at the next sign-in on any computer; a place they can no longer reach falls back to all.
- Switching asks first when a form has unsaved changes. On a phone the picker is one button.

### Fixed
- Choosing an industry hid **Export to QuickBooks / Xero / Sage** from the owner (since the industry box): pages that need company-wide rights now depend on the person's rights, not on the place being looked at.
- An audit log entry whose change held a list inside a list (saved preferences, e.g. the Business Portal layout or the recent places) did not open: "Array to string conversion". Such values are now written out in words.
