<?php

namespace App\Filament\Support;

use App\Models\Bin;
use App\Models\ProductUom;
use App\Models\Warehouse;
use App\Support\Qty;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Form fields shared by the purchasing and warehouse screens. */
final class Fields
{
    public static function unitLabel(ProductUom $u): string
    {
        $u->loadMissing('product', 'uom');

        return "{$u->product->sku} · {$u->product->name} — {$u->uom->code}".($u->is_base ? '' : ' × '.rtrim(rtrim((string) $u->factor, '0'), '.'));
    }

    /**
     * A line's optional extras (batch, expiry, cost, reason, note…) below the line, shown by the line's own Details
     * button ({@see detailsToggle()}). Shown and hidden in the browser, so what was typed is kept (and saved) while
     * folded away. It opens by itself for an edited line that holds extras, or for a batch-tracked product.
     *
     * @param  list<Component>  $fields
     */
    public static function more(array $fields, int $columns = 4): Group
    {
        return Group::make($fields)->columns($columns)->columnSpanFull()->visibleJs("\$get('more')");
    }

    /**
     * The end-of-line "Details" button (a drop-down for the line's extras) and its open/closed state. The state is not
     * saved. $open decides the first state of a line from its saved values.
     *
     * @return list<Component>
     */
    public static function detailsToggle(): array
    {
        return [
            Hidden::make('more')->dehydrated(false)->default(false),
            Actions::make([Action::make('details')->label('Details')->icon(Heroicon::ChevronUpDown)->iconPosition('after')->link()->color('gray')
                ->alpineClickHandler("\$set('more', ! \$get('more'))")])->alignment(Alignment::End)->verticallyAlignEnd(),
        ];
    }

    /** Opens the line's Details by itself when the chosen product tracks batches (medicines): the batch is not forgotten. */
    public static function openForBatches(Select $productUnit): Select
    {
        return $productUnit->afterStateUpdated(function ($state, Set $set) {
            if ($state && ProductUom::with('product')->find($state)?->product?->track_batches) {
                $set('more', true);
            }
        });
    }

    /**
     * Stock of the chosen unit's product at a store (and of one batch when its number is given): in the chosen unit,
     * with the average cost per base unit. Null when no product or store is chosen yet.
     *
     * @return array{qty: float, unit: string, factor: float, cost: ?float, batch: ?float}|null
     */
    public static function stockOf(?string $productUomId, ?string $warehouseId, ?string $batchNo = null): ?array
    {
        $u = ($productUomId && $warehouseId) ? ProductUom::with('uom')->find($productUomId) : null;
        if (! $u) {
            return null;
        }
        $factor = (float) $u->factor ?: 1.0;
        $row = DB::table('stock_levels')->where('warehouse_id', $warehouseId)->where('product_id', $u->product_id)->first(['on_hand', 'avg_cost_minor']);
        $batch = filled($batchNo) ? DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')
            ->where('l.warehouse_id', $warehouseId)->where('l.product_id', $u->product_id)->where('b.batch_no', trim($batchNo))->sum('l.on_hand') : null;

        return ['qty' => (float) ($row->on_hand ?? 0) / $factor, 'unit' => $u->uom->code, 'factor' => $factor,
            'cost' => isset($row->avg_cost_minor) ? (float) $row->avg_cost_minor : null, 'batch' => $batch === null ? null : (float) $batch / $factor];
    }

    /** "12 PK" (and "batch B123: 4 PK"): what the line's store holds of the product now. */
    public static function available(string $warehousePath, ?string $batchField = null): TextEntry
    {
        return TextEntry::make('available')->label('Available')->state(function (Get $get) use ($warehousePath, $batchField) {
            $s = self::stockOf($get('product_uom_id'), $get($warehousePath), $batchField ? $get($batchField) : null);
            if (! $s) {
                return '—';
            }

            return self::qty((string) round($s['qty'], 3), $s['unit']).($s['batch'] !== null ? ' · batch '.self::qty((string) round($s['batch'], 3)) : '');
        })->color(fn ($state) => str_starts_with((string) $state, '-') ? 'danger' : null);
    }

    /**
     * "Also on line 2" when the same product (and batch, when given) is on another line of the same document — usually
     * a mistake; two lines are still allowed (e.g. two batches).
     */
    public static function duplicateWarning(?string $batchField = null): TextEntry
    {
        $others = function (Get $get, TextEntry $component) use ($batchField): array {
            $mine = $get('product_uom_id');
            if (! $mine) {
                return [];
            }
            $product = ProductUom::find($mine)?->product_id;
            $self = last(explode('.', $component->getContainer()->getStatePath()));
            $lines = array_keys((array) $get('../../lines'));
            $hits = [];
            foreach ((array) $get('../../lines') as $key => $line) {
                if ($key === $self || ! ($line['product_uom_id'] ?? null) || ProductUom::find($line['product_uom_id'])?->product_id !== $product) {
                    continue;
                }
                if ($batchField && trim((string) ($line[$batchField] ?? '')) !== trim((string) $get($batchField))) {
                    continue;
                }
                $hits[] = array_search($key, $lines, true) + 1;
            }

            return $hits;
        };

        return TextEntry::make('duplicate')->hiddenLabel()->color('warning')->icon(Heroicon::ExclamationTriangle)->columnSpanFull()
            ->state(fn (Get $get, TextEntry $component) => ($hits = $others($get, $component)) ? 'This product'.($batchField ? ' and batch' : '').' is also on line '.implode(', ', $hits).'.' : null)
            ->visible(fn (Get $get, TextEntry $component) => $others($get, $component) !== []);
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
    /** @param  string|list<string>  $permission  any of these */
    public static function warehouses(string|array $permission): array
    {
        $ids = [];
        foreach ((array) $permission as $p) {
            $branches = Erp::branchIds($p);
            if ($branches === null) {
                $ids = null;
                break;
            }
            $ids = array_merge($ids, $branches);
        }

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
