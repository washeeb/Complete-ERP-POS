## Markdowns and expiry returns in the new back office
- **The markdown pages reuse the rebuilt Batches & expiry page** with an industry, as the classic pages extend BatchesExpiry: one set of code, the industry's stores and access rule, and no full batch list.
- **Disposal certificates go up as a form** (like supplier documents), stored before the disposal is prepared, as the current form does.
- **With no store to handle expiry in, the list is empty**, not every store: an empty store list would have meant "no filter" to `ExpiryService::batches` (missing scope is not global scope).
- **Returns to suppliers stay on the current screens** for now; Expiry & returns links there and a prepared return opens there.
