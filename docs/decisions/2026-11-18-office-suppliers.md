## Suppliers in the new back office
- **Same services, same rules.** Saving goes through `SupplierService::save` (with the products), approve, block, unblock, verify bank details and the documents through `SupplierOnboarding`, the portal link through `SupplierPortal`, the opening balance through `SupplierPaymentService::opening`, archive and restore through `ArchiveService`.
- **One page per supplier.** The current screen has only a list (with the actions on each row) and the edit form with a Documents tab. The new back office gives each supplier a page, like customers; the classic edit address opens it.
- **Bank details are shown only to the people who keep or check them** (manage or approve suppliers). Others see that details are on file. Before, anyone who could open the edit form saw them, and only managers could open it, so nothing changes for them.
- **Opening balance follows the service's own check.** The current button shows for `finance.journal` held anywhere, while the service asks for it company-wide; the new button asks what the service asks, so nobody sees a button that refuses them.
- **Products supplied search** offers buying units of every active product (as the current form), not only stocked ones as the stock screens do.
- **A document's file is kept only if the document is saved;** a refused upload removes the file again.
