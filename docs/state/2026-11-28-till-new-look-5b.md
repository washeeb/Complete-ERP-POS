## Till new look, part 5b: the industry screens (branch `till-new-look-5b`, base `till-new-look-5a`)
- **Checked by screenshot (18 windows):** each industry test photographed every window it opens at 1366×768.
- **ProductTiles:** `hidden` (restaurant `service_charge.product_id`, fashion `alteration_product_id`) never shown; `styles`: one tile per fashion style (first variant stands for it; Sell's `add()` opens the StylePicker), named after the style, no stock note. E2E: fashion (one Kente dress tile, no Alteration), restaurant (no Service charge tile).
- **Prescription (`.rx`):** rows of fields share the width (`:has(> label)`), the patient line spreads out.
- **Handheld e2e:** the out-of-box scan is dispatched in the page as one burst (Playwright's keyboard sends each key on its own round trip, ~25 ms apart, more under load; the trace showed 176 ms for 7 keys).
- **Remaining local flakes under load:** offline-till "sign-in ran out" (the connection check timed out, the till went offline), a11y "prints by itself" (4 s sale wait); both are the till's safeguards, pass alone and in CI.
- **The till restyle is complete** (parts 1–5b).
