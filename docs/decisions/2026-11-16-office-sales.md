## Till sales rebuilt; every request limit on its own counter (2026-11-16)
- **The first rebuilt list sets the pattern for the others.**
  - The server keeps the current screen's rules: who sees which branches, the same filters, the same detail.
  - The list's state lives in the address, so Back, reload and links work.
  - A reusable Fluent table with sortable headings and page controls.
  - "Classic version" stays one click away until the old screens are retired.
- **Rebuilt pages are listed in one place (`REBUILT`).** A menu link, a search result or a To-do item for that page opens the new screen; nothing else changes. Each later screen is added there.
- **Request limits.**
  - The new back office's checks found the favourite star refused after two changes. The cause, confirmed in Laravel's own code and reproduced in a test: a plain `throttle:N,M` is counted per person (or per address), not per route, so every limited route shared one counter. The same applied to the online shop's cart and checkout.
  - **Why fix all of them now:** it was a fault for every install; the new back office only made it visible.
