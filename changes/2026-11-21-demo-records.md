### Added
- **`php artisan erp:demo-records --as=<owner-email>`** (test installations only): adds a sample of each kind of record a demo or training company has none of yet, through the same services as the screens: a bank account with a statement to reconcile and money moved, an expense claim, a supplier bill, payment and invoice, a fixed asset, a direct purchase, a stock adjustment and request, a return, a saved report, a markdown and a return to a supplier, the drug-interaction list, the health-check services (HB, HIV, typhoid, wound dressing) with a reading each, pharmacy sales with a prescription, a controlled medicine, an NHIS claim and a refill reminder, a till paid-out, a closed shift and a cash handover, a float issued, a message and a closed day. Run again, it adds nothing twice.

### Changed
- **Controlled-drug register:** lists only the stores of branches with pharmacy on (new and current screens), so a company with a grocery or restaurant branch no longer opens it on a store where controlled medicines never move.

