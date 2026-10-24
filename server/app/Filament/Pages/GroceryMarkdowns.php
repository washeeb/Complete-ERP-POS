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
 * only (PharmacyMarkdowns: the same for the pharmacies). The same work as Stock → Batches & expiry, without the full batch list, where grocery staff look for it.
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

    /** The industry whose menu this page sits in (Pharmacy → Near-expiry markdowns extends it). */
    protected static string $module = 'grocery';

    /** Only for people who can see a branch that runs the module (a pharmacist at a pharmacy branch does not get the Grocery link). */
    public static function canAccess(): bool
    {
        if (! (Erp::canAnywhere('grocery.markdowns') || Erp::canAnywhere('stock.view')) || ! ModuleService::enabledAnywhere(static::$module)) {
            return false;
        }
        $ids = Erp::branchIds('stock.view');

        return DB::table('branch_modules')->where('module_key', static::$module)->where('status', 'enabled')
            ->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))->exists();
    }

    /** Warehouses of the branches that run the module (and the person may see). */
    public function warehouses(): array
    {
        $ids = Erp::branchIds('stock.view');
        $branches = DB::table('branch_modules')->where('module_key', static::$module)->where('status', 'enabled')->pluck('branch_id');

        return Warehouse::whereIn('branch_id', $branches)->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))->orderBy('code')->get()
            ->mapWithKeys(fn ($w) => [$w->id => "{$w->code} · {$w->name}"])->all();
    }
}
