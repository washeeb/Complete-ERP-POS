## Bank reconciliation: recent matches in true order (branch `fix-banking-reconcile-order`, base `main`)
- `App\Domain\Office\BankingScreen::reconcile()` and `App\Filament\Pages\BankReconciliation` list `bank_matches` by `created_at` desc, then `id` desc. `created_at` is stored to the second; the ids are uuid7, so they keep the true order within a second.
- `OfficeBankingTest` undoes the deposit's match by its amount (not "the first in the list"), and checks the order: matched earlier, then the bank charge, then the deposit.
