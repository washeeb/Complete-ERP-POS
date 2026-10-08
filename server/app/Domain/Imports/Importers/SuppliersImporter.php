<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Imports\Importer;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\Actor;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;

/** Suppliers: matched by supplier code; a new code creates the supplier. Empty cells leave a detail unchanged. */
final class SuppliersImporter extends Importer
{
    public function __construct(private readonly SupplierService $suppliers) {}

    public function key(): string
    {
        return 'suppliers';
    }

    public function label(): string
    {
        return 'Suppliers (create and update)';
    }

    public function description(): string
    {
        return 'One row per supplier, matched by code (letters, digits and dashes, up to 20). A new code creates the supplier; empty cells leave a detail unchanged.';
    }

    public function permission(): string
    {
        return 'suppliers.manage';
    }

    public function columns(): array
    {
        return [
            ['name' => 'code', 'required' => true, 'help' => 'Supplier code.', 'example' => 'OLAM'],
            ['name' => 'name', 'required' => 'new', 'help' => 'Supplier name.', 'example' => 'Olam Ghana Ltd'],
            ['name' => 'contact_name', 'required' => false, 'help' => 'Contact person.', 'example' => 'Ama Boateng'],
            ['name' => 'phone', 'required' => false, 'help' => 'Phone.', 'example' => '0302123456'],
            ['name' => 'email', 'required' => false, 'help' => 'E-mail (purchase orders are sent there).', 'example' => 'orders@example.com'],
            ['name' => 'tax_no', 'required' => false, 'help' => 'TIN / VAT number.', 'example' => ''],
            ['name' => 'address', 'required' => false, 'help' => 'Address.', 'example' => 'Tema'],
            ['name' => 'payment_terms_days', 'required' => false, 'help' => 'Days you have to pay their bills.', 'example' => '30'],
            ['name' => 'lead_time_days', 'required' => false, 'help' => 'Days from order to delivery (for reorder suggestions).', 'example' => '7'],
            ['name' => 'active', 'required' => false, 'help' => 'yes / no.', 'example' => 'yes'],
        ];
    }

    public function apply(Actor $actor, array $row, array $options, ?Model $context): string
    {
        $code = strtoupper(self::need($row, 'code', 'the supplier code'));
        $supplier = Supplier::where('code', $code)->first();
        $data = ['code' => $code];
        if (! $supplier) {
            $data['name'] = self::need($row, 'name', 'the name (new supplier)');
        }
        foreach (['name', 'contact_name', 'phone', 'email', 'tax_no', 'address'] as $f) {
            if (self::has($row, $f)) {
                $data[$f] = $row[$f];
            }
        }
        foreach (['payment_terms_days', 'lead_time_days'] as $f) {
            if (self::has($row, $f)) {
                $data[$f] = (int) self::number($row[$f], $f);
            }
        }
        if (self::has($row, 'active')) {
            $data['is_active'] = self::bool($row['active'], 'active');
        }
        $this->suppliers->save($actor, $supplier ?? new Supplier, $data);

        return $supplier ? 'updated' : 'created';
    }

    public function exportRows(Actor $actor, array $options, ?Model $context): iterable
    {
        foreach (Supplier::orderBy('code')->lazy(500) as $s) {
            yield [$s->code, $s->name, $s->contact_name, $s->phone, $s->email, $s->tax_no, $s->address, $s->payment_terms_days, $s->lead_time_days, $s->is_active ? 'yes' : 'no'];
        }
    }
}
