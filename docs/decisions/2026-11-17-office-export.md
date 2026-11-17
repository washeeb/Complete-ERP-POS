## Export on the rebuilt lists
- **Found:** every classic table gets Export (Excel, CSV, PDF) from `TableExport::group()`. The rebuilt lists of releases 2 and 3 had none, which is a feature the new back office had quietly lost.
- **One export for all of them, from the screens' own queries.**
  - Each screen's list is now split into filtered query, order and row. Both the list and `ListExport` use them, so a file always matches the screen: same search, filters, order and branch or warehouse scope.
  - A separate export query per list would drift.
- **The same Exporter as the classic back office:** licence check, audit row, CSV-injection guard, the 800-row PDF limit, and file names and layout identical to today's exports.
- **The browser fetches the file instead of following a link,** so a refusal (too many rows, or a licence without output) is shown as a message and not as a page of raw JSON.
- **Every list rebuilt from now on adds its export case** (state note).
