## NHIS claims and sales to sign off in the new back office
- **Found while rebuilding:** the classic NHIS claim page crashed for any claim with sales ("undefined relationship [customer] on model Sale"); its test only opened the page, not the sales list, and an empty claim never loads it. Proved with a test that renders the list for a real claim (failed before, passes after adding `Sale::customer()`).
- **A claim gets its own page** (not a dialog): it has several steps over weeks and a long list of sales.
- **Temperature log is not part of pharmacy:** it sits under Sensors in the menu and is rebuilt with the sensors screens. **Markdowns** are shared with grocery, so pharmacy and grocery markdowns are rebuilt together in part 13 with expiry returns.
