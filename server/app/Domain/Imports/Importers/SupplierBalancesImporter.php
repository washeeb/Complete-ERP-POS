<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Imports\Importer;
use App\Domain\Payables\SupplierPaymentService;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * What the company owed its suppliers on the go-live date (data migration): one open item per supplier, paid later
 * like a bill; the automatic posting balances it against "Opening balances (to clear)".
 */
final class SupplierBalancesImporter extends Importer
{
    public function __construct(private readonly SupplierPaymentService $payments) {}

    public function key(): string
    {
        return 'supplier_balances';
    }

    public function label(): string
    {
        return 'Supplier balances owed (migration)';
    }

    public function description(): string
    {
        return 'One row per supplier you owed money on the go-live date. supplier is the supplier code or exact name. '
            .'due_on (optional) is when it must be paid; reference is their invoice number. A supplier can only get an opening balance before their first bill.';
    }

    public function permission(): string
    {
        return 'finance.journal';
    }

    public function optionDefaults(): array
    {
        return ['as_of' => now()->toDateString()];
    }

    public function columns(): array
    {
        return [
            ['name' => 'supplier', 'required' => true, 'help' => 'Supplier code or exact name.', 'example' => 'OLAM'],
            ['name' => 'balance', 'required' => true, 'help' => 'What you owe them.', 'example' => '8400.00'],
            ['name' => 'due_on', 'required' => false, 'help' => 'Date it must be paid.', 'example' => '2026-11-30'],
            ['name' => 'reference', 'required' => false, 'help' => 'Their invoice number(s).', 'example' => 'INV 5521'],
        ];
    }

    public function reference(array $row): string
    {
        return (string) ($row['supplier'] ?? '');
    }

    public function apply(Actor $actor, array $row, array $options, ?Model $context): string
    {
        $supplier = Lookup::supplier(self::need($row, 'supplier', 'the supplier'));
        $amount = Money::toMinor(self::number(self::need($row, 'balance', 'the balance'), 'balance'), Tenant::find($actor->tenantId)->currency_code);
        if ($amount === 0) {
            return 'unchanged';
        }
        if ($amount < 0) {
            throw new DomainException('A negative balance means the supplier owes you: record it as a supplier credit with your accountant.');
        }
        $this->payments->opening($actor, $supplier, $amount, (string) ($options['as_of'] ?? now()->toDateString()),
            self::has($row, 'due_on') ? substr(self::date($row['due_on'], 'due_on'), 0, 10) : null, self::has($row, 'reference') ? trim($row['reference']) : null);

        return 'added';
    }
}
