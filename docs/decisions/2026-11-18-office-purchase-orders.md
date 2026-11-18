## Purchase orders in the new back office
- **Same services, same rules.** Drafts through `PurchaseOrderService::saveDraft` (pack rules, blocked or unapproved suppliers, agreements and budgets checked there), the buttons through `PurchaseOrderService`, `OrderSender` and `GoodsReceiptService::receive`, Order from a file through `OrderImporter`.
- **Approve is hidden on one's own order.** The service refuses it ("someone else must approve it"); the current page showed the button anyway. The new page shows it only to someone else who may approve.
- **Receive goods stays on the order**, as on the current page, with the same fields; the goods received list itself is rebuilt next (part 3).
- **The pack code is read on the server** (`Gs1::parse`, the same reader the current form uses), so both screens understand the same codes.
- **Adding a supplier from the order** uses the current quick-create rule: one already there under that name or code is chosen instead of making a second.
- **Branch built on top of suppliers** so the two PRs do not change the same lines twice; it merges after PR 211.
