## Stock reports in the new back office
- **The current pages' queries are reused, not copied:** the screen sets the classic page's filters and calls its methods, so the old and new pages cannot drift apart while both exist.
- **Move asks first, then opens the draft transfer** to check and dispatch, as the current page does; nothing leaves the store until it is dispatched.
- **Part 4 split in three PRs** (shrink and slow stock; batches and expiry; returns): batches and returns each carry their own actions and are easier to check alone.
