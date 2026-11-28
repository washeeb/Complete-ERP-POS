## Till new look, part 3: drawer, close shift, and scan timing
- **The Drawer reuses the payment window's tiles,** so the till has one way of choosing "what kind of money movement" everywhere.
- **Close shift fits one till screen** at 1366 × 768: nothing to scroll past at the end of a long day, and Close shift is always in reach.
- **Scan timing (found by the handheld test):** a scan typed while the cursor was not in a box was told apart from a person by the gaps between keys, measured when the page handled them; on a busy or slow till that is late, and a scan could be missed. Now measured when the key was pressed (`event.timeStamp`), with up to 50 ms between keys (scanners send 1–5 ms; people 70–80 ms or more).
