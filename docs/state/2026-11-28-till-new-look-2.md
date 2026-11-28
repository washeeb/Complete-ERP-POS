## Till new look, part 2: payment and receipt (branch `till-new-look-2`, base `till-favourites`)
- **Pay.tsx:** `ICON` per way to pay (Fluent icons, aria-hidden, names unchanged), `.pay-totals` (due large, brand foreground), `.pay-methods` grid tiles (role tablist "Ways to pay"), `.quick-cash` group "Cash received: quick amounts".
- **Receipt.tsx:** `.receipt-paper` (receipt and kitchen ticket, printed as before) + `.done-panel` (region "Sale complete" / "Refund done", no-print): total, *Change to give* (`change_minor`), New sale (autofocus), print buttons, SendReceipt (grid). Print receipt is no longer a primary button (New sale is the one main action). Print CSS: `.till-root` white, no shadow on the receipt.
- **Tests:** a11y e2e mock computes `change_minor` from the tendered amounts; new test: quick GH₵ 60 → *Change to give* GH₵ 2.00, New sale focused. Axe passes on Payment and Receipt in light and dark.
- **Next:** part 3, drawer, shifts and cash-up.
