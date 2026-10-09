<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Imports\Importer;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\Actor;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Suppliers: matched by supplier code, else by exact name; a new code creates the supplier. Without a code (lists from
 * QuickBooks or other systems) a code is made from the name. Empty cells leave a detail unchanged.
 */
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
        return 'One row per supplier, matched by code (letters, digits and dashes, up to 20), else by the exact name. A new code creates the supplier; '
            .'with no code, one is made from the name (e.g. OLAM-GHANA-LTD). Empty cells leave a detail unchanged.';
    }

    public function permission(): string
    {
        return 'suppliers.manage';
    }

    public function columns(): array
    {
        return [
            ['name' => 'code', 'required' => false, 'help' => 'Supplier code. Empty = matched by name, or made from the name.', 'example' => 'OLAM'],
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

    public function reference(array $row): string
    {
        return (string) ($row['code'] ?? '') ?: (string) ($row['name'] ?? '');
    }

    public function apply(Actor $actor, array $row, array $options, ?Model $context): string
    {
        if (self::has($row, 'code')) {
            $code = strtoupper(trim($row['code']));
            $supplier = Supplier::where('code', $code)->first();
        } else {
            $name = self::need($row, 'name', 'the code or the name');
            $supplier = Supplier::whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])->first();
            $code = $supplier?->code ?? self::codeFrom($name);
        }
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

    /** "Olam Ghana Ltd." → OLAM-GHANA-LTD (unique, at most 20 characters). */
    public static function codeFrom(string $name): string
    {
        $base = substr(trim((string) preg_replace('/[^A-Z0-9]+/', '-', strtoupper(Str::ascii($name))), '-'), 0, 16) ?: 'SUP';
        $code = rtrim($base, '-');
        for ($i = 2; Supplier::where('code', $code)->exists(); $i++) {
            $code = rtrim($base, '-')."-{$i}";
        }

        return $code;
    }

    public function exportRows(Actor $actor, array $options, ?Model $context): iterable
    {
        foreach (Supplier::orderBy('code')->lazy(500) as $s) {
            yield [$s->code, $s->name, $s->contact_name, $s->phone, $s->email, $s->tax_no, $s->address, $s->payment_terms_days, $s->lead_time_days, $s->is_active ? 'yes' : 'no'];
        }
    }
}
