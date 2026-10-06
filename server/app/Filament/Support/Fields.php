<?php

namespace App\Filament\Support;

use App\Models\Bin;
use App\Models\ProductUom;
use App\Models\Warehouse;
use App\Support\Qty;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

/** Form fields shared by the purchasing and warehouse screens. */
final class Fields
{
    public static function unitLabel(ProductUom $u): string
    {
        $u->loadMissing('product', 'uom');

        return "{$u->product->sku} · {$u->product->name} — {$u->uom->code}".($u->is_base ? '' : ' × '.rtrim(rtrim((string) $u->factor, '0'), '.'));
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
