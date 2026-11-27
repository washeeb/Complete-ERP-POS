## The till's new look, part 1
- **Owner 2026-11-28:** tiles + cart (with a setting to keep the plain list in the new look), tiles free, the brand on Pay and main buttons, selling screen first.
- **Fluent around the whole till at once, with a CSS bridge:** the till's stylesheet variables point at Fluent's theme tokens, so all 26 screens take the new colours (and the brand contrast rules) in part 1, while their layouts are rebuilt part by part. No screen keeps the old colours meanwhile, and each part stays a reviewable size.
- **One brand theme for both apps:** the till imports the back office's `themes()`, so the contrast rules (part 47) apply to both from one place.
- **Tiles from the catalogue's categories** need no setup: every till has them on day one; choosing favourites per branch (part 1b) adds to them.
- **Every button kept its name,** so the 30 till browser tests guarded the change; tests that matched text a tile can also show were scoped to the Payment window and the Cart.
- **Fluent's focus sentinels are left out of the axe check** (`[data-tabster-dummy]`): hidden, without content, and moving focus on at once; the arrow-key navigation they serve is worth more than the warning.
- **Size:** +51 KB gzip, downloaded once per update and kept offline.
