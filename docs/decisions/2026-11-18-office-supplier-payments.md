## Supplier payments and aged payables in the new back office
- **Same service, same rules.** Pay a supplier goes through `SupplierPaymentService::pay` (oldest bills first, withholding tax, verified bank or MoMo details, confirm it's you above the limit), as the current dialog.
- **The withholding tax preview is the server's**, worked out by `WithholdingTax::compute` on the bills the amount would settle, as the current dialog does, so what the person sees is what is kept back.
- **One Pay dialog for both pages**, and a Pay button on each row of Aged payables with the supplier chosen (the current page has only the one at the top).
- **A payment row opens its supplier**; the current list has no page per payment.
