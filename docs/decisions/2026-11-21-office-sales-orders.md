## Quotes & orders and receivables in the new back office
- **Part 15 is the receivables half of Sales** (quotes and orders, payments received, aged receivables); till requests, back-office returns, the review queue and gift cards follow in part 16, to keep each part reviewable.
- **Release credit hold is hidden from the person who entered the order**, as Approve is on the rebuilt purchase orders: `CustomerOrderService::release` already refuses one's own order, so the button only led to an error.
- **A customer is chosen by typing** (name, code or phone), not from a full list: a wholesale business can have thousands. A missing customer is added from the form as the current quick-create does (one with the same phone is chosen instead).
- **Aged receivables stays company-wide**, as the current page: customer balances are not kept per branch.
