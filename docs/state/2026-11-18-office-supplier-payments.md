## New back office, release 6 part 3: supplier payments and aged payables (branch `office-supplier-payments`, stacked on `office-supplier-bills`)
- **Server:** `App\Domain\Office\SupplierPaymentsScreen` (`filtered` / `sorted` / `rowOf` on `SupplierPaymentResource::getEloquentQuery`: q on number, reference, supplier; method; supplier; cards settled and WHT; `payForm`; `whtPreview` as the current dialog's; `aging` from `SupplierLedger::aging` with totals). `Office\SupplierPaymentsController`: index, options, aging, preview, pay (`SupplierPaymentService::pay`; `ReconfirmNeeded` → 428). `ListExport` gains `supplier-payments`.
- **Client:** `pages/PaySupplier.tsx` (shared dialog: preview as typed, Confirm it's you), `SupplierPaymentsPage.tsx`, `AgedPayablesPage.tsx`. `REBUILT` maps `/admin/supplier-payments` and `/admin/aged-payables`.
- **office-check:** both open from the Finance menu; the Pay a supplier dialog with a supplier and amount (nothing paid).
- **Tests:** OfficeSupplierPaymentsTest (3).
- **Next:** release 6 part 4, withholding tax.
