## The restaurant in the new back office
- **One bill opens in a dialog, not its own page:** a bill is read-only (it is worked at the till) and short; the bill shown lives in the address (`?bill=`), so the current page's links to a bill still land on it.
- **Tips & voids uses the current page's own queries** (its period and branches), so both always show the same figures.
- **One archive endpoint for the restaurant's tables, stations and modifier groups**, each checked against its own list and ArchiveService's rules (a table with an open order cannot be archived).
- **office-check's "old page inside" step moved to Stock → Equipment** (Maintenance), the next page not rebuilt that the dev company has switched on.
