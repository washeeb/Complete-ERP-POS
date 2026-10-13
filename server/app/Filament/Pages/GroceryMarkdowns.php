<?php

namespace App\Filament\Pages;

use App\Domain\Modules\ModuleService;
use App\Filament\Support\Erp;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Grocery → Markdowns: near-expiry markdowns and their stickers, and expired stock to write off, for the grocery stores
 * only. The same work as Stock → Batches & expiry, without the full batch list, where grocery staff look for it.
 */
class GroceryMarkdowns extends BatchesExpiry
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Grocery';

    protected static ?string $navigationLabel = 'Markdowns';

    protected static ?string $title = 'Near-expiry markdowns';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'grocery/markdowns';

    public bool $markdownsOnly = true; // without the full batch list

    public static function canAccess(): bool
    {
        return (Erp::canAnywhere('grocery.markdowns') || Erp::canAnywhere('stock.view')) && ModuleService::enabledAnywhere('grocery');
    }

    /** Warehouses of the branches that run Grocery (and the person may see). */
    public function warehouses(): array
    {
        $ids = Erp::branchIds('stock.view');
        $grocery = DB::table('branch_modules')->where('module_key', 'grocery')->where('status', 'enabled')->pluck('branch_id');

        return Warehouse::whereIn('branch_id', $grocery)->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))->orderBy('code')->get()
            ->mapWithKeys(fn ($w) => [$w->id => "{$w->code} · {$w->name}"])->all();
    }
}
