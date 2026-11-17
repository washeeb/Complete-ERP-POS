## Stock adjustments in the new back office
- **Every rule stays in StockAdjustmentService.** The form saves through `saveDraft`, which replaces the lines in order, and the actions call `submit`, `post`, `reopen` and `cancel`. Approval above the limit by someone else, photos of losses, batch and serial rules and disposals by a second person therefore behave exactly as before. A refused line says its number ("Line 2: …").
- **The approval warning uses the gross value**, as posting does, not the net value shown (a decrease shows as negative).
- **Live figures on each line** (Available, After, Value) come from a small shared endpoint over `Fields::stockOf`, limited to warehouses the person may see. Transfers, counts and requests will use the same endpoint and `UnitPicker`.
- **Photos** are uploaded when chosen (validated as an image, at most 5 MB) and kept on the private disk under `stock-photos/`. The service only accepts a path there, as before.
- **Found:** the current form warns when the same product (and batch) is on two lines. The new form now does too (`alsoOn`).
- **Checked without touching the dev data:** the browser check opens the form but saves nothing, and draws one adjustment from an answer made up in the browser. Posting is proven by the server tests.
