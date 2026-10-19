<?php

namespace App\Filament\Support;

use App\Models\Bin;
use App\Models\ProductUom;
use App\Models\Warehouse;
use App\Support\Qty;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;

/** Form fields shared by the purchasing and warehouse screens. */
final class Fields
{
    public static function unitLabel(ProductUom $u): string
    {
        $u->loadMissing('product', 'uom');

        return "{$u->product->sku} · {$u->product->name} — {$u->uom->code}".($u->is_base ? '' : ' × '.rtrim(rtrim((string) $u->factor, '0'), '.'));
    }

    /**
     * A line's optional extras (expiry, reason, note…) folded under "More details" so the line stays short; it opens by
     * itself when one of them already holds a value (an edited draft), and a click opens it when needed.
     *
     * @param  list<Field>  $fields
     */
    public static function more(array $fields, int $columns = 4): Section
    {
        $names = array_map(fn (Field $f) => $f->getName(), $fields);

        return Section::make('More details')->compact()->collapsible()->columnSpanFull()->columns($columns)
            ->collapsed(fn (Get $get) => collect($names)->every(fn ($n) => blank($get($n))))->schema($fields);
    }

    /** True when the product of the chosen unit is serial-numbered (phones, laptops…): only then ask for serials. */
    public static function serialised(?string $productUomId): bool
    {
        return $productUomId !== null && (bool) ProductUom::with('product')->find($productUomId)?->product?->track_serials;
    }

    /** Search products by name, SKU or barcode; the value is the product unit (e.g. "Milo — CTN × 24"). */
    public static function productUnit(string $name = 'product_uom_id'): Select
    {
        return Select::make($name)->label('Product and unit')->required()->searchable()
            ->getSearchResultsUsing(fn (string $search) => ProductUom::with('product', 'uom')
                ->whereHas('product', fn (Builder $q) => $q->where('is_active', true)->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('sku', 'ilike', "%{$search}%")))
                ->orWhereHas('barcodes', fn ($q) => $q->where('code', $search))
                ->limit(30)->get()->mapWithKeys(fn ($u) => [$u->id => self::unitLabel($u)]))
            ->getOptionLabelUsing(fn ($value) => ($u = ProductUom::find($value)) ? self::unitLabel($u) : null);
    }

    /** Warehouses where the signed-in person holds the permission. */
    public static function warehouses(string $permission): array
    {
        $ids = Erp::branchIds($permission);

        return Warehouse::where('is_active', true)->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))
            ->orderBy('code')->get()->mapWithKeys(fn ($w) => [$w->id => "{$w->code} · {$w->name}"])->all();
    }

    public static function bins(?string $warehouseId): array
    {
        return Bin::where('warehouse_id', $warehouseId)->where('is_active', true)->orderBy('pick_order')->orderBy('code')
            ->get()->mapWithKeys(fn ($b) => [$b->id => $b->code.($b->name ? " · {$b->name}" : '')])->all();
    }

    public static function qty(string|int|float|null $value, ?string $uom = null): string
    {
        return rtrim(rtrim(Qty::format(Qty::toMilli((string) ($value ?? '0'))), '0'), '.').($uom ? " {$uom}" : '');
    }
}
