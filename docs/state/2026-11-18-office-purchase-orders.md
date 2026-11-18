## New back office, release 5 part 2: purchase orders (branch `office-purchase-orders`, stacked on `office-suppliers`)
- **Server:**
  - `App\Domain\Office\PurchaseOrdersScreen`: `filtered` / `sorted` / `rowOf` on `PurchaseOrderResource::getEloquentQuery` (status[] multi, supplier, warehouse, q on number and supplier name); `list` cards (sum of non-drafts, waiting, open); `show` with each action's data (send channels, confirm-delivered service lines, receive lines with batched/serialised and bins); `form` / `toDomain` (stock lines carry the unit, service lines the account, description, unit, department, project); `lineInfo` (pack rule and stock in the store).
  - `Office\PurchaseOrdersController`: index, options, show, form, units (buying units, as the supplier form), lineInfo, packCode (`Gs1::parse`), addSupplier (QuickCreate's duplicate check, then `SupplierService`), save, `action` (submit, approve, reject, send, copy, confirm_delivered, receive, close_short, cancel), `import` (multipart; a list is deleted after reading, a pro-forma kept with the AI record).
  - `ListExport` gains `purchase-orders`.
- **Client:** `pages/PurchaseOrdersPage.tsx` (with Order from a file), `PurchaseOrderPage.tsx` (dialogs: Confirm, Reason, Send, ConfirmDelivered, Receive), `PurchaseOrderFormPage.tsx` (lines, add-supplier dialog). `REBUILT` maps the list, `/create`, `/{uuid}`, `/{uuid}/edit`.
- **office-check:** the list, an order, and the new-order form (Save off while empty; a service line asks "What"; nothing saved).
- **Tests:** OfficePurchaseOrdersTest (4).
- **Next:** release 5 part 3, goods received.
