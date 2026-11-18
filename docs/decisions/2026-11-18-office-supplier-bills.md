## Supplier bills in the new back office
- **Same services, same rules.** Bills through `BillService` (never more than received, the price tolerance, approval by someone else), scans through `InvoiceScanner`, the supplier's own invoice marked entered through `SupplierPortal`, as the current create page does after saving.
- **The form takes a scan or a sent invoice from the address** (`?scan=` / `?invoice=`), as the current one; the classic address carries them over, so Invoices from suppliers' buttons open the rebuilt form.
- **Add a supplier from the bill** has its own endpoint: the purchase order one needs purchasing rights accountants may not have. It keeps the current rule (only people who manage suppliers, and an existing supplier of that name or code is chosen instead).
- **Choosing another supplier clears the received and order lines**, as now (they belong to the supplier); other charges stay.
