## New back office, release 5 part 5: invoices from suppliers and consignment to pay (branch `office-supplier-invoices`, stacked on `office-direct-purchases`)
- **Server:** `App\Domain\Office\SupplierInvoicesScreen` (`filtered` on `SupplierInvoiceResource::getEloquentQuery`: q on their invoice number or supplier; status '' = To enter, `all`, or a status; `rowOf` with the actions open / read / enter / mark / reject; `bills` for Mark entered; `consignment` from `ConsignmentService::owed`). `Office\SupplierInvoicesController`: index, options, bills, `action` (read → `InvoiceScanner::scan`, answering where the draft bill opens; mark → `SupplierPortal::markEntered`; reject → `SupplierPortal::reject`), consignment, settle (`ConsignmentService::settle`).
- **Client:** `pages/SupplierInvoicesPage.tsx` (a row opens a dialog with its buttons), `ConsignmentPage.tsx` (table and Settle). `REBUILT` maps `/admin/supplier-invoices` and `/admin/consignment-to-pay`.
- **office-check:** both open from the Purchasing menu and show their list (waits until loaded).
- **Tests:** OfficeSupplierInvoicesTest (3).
- **Next:** release 5 part 6, reorder levels, what to order and suggested levels.
