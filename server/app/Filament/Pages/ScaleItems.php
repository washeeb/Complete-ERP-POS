<?php

namespace App\Filament\Pages;

use App\Domain\Modules\ModuleService;
use App\Filament\Support\Erp;
use App\Filament\Support\ExportsPage;
use App\Support\IndustryContext;
use App\Support\Settings;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Grocery → Scale items: the weighed products with a scale code (PLU), their unit and retail price, as the scales need
 * them. Export it to load the scales; the till reads the labels they print (prefix, PLU, weight).
 */
class ScaleItems extends Page
{
    use ExportsPage;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Grocery';

    protected static ?string $navigationLabel = 'Scale items';

    protected static ?string $title = 'Scale items';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'grocery/scale-items';

    protected string $view = 'filament.pages.scale-items';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('catalog.view') && ModuleService::enabledAnywhere('grocery');
    }

    public function prefixes(): string
    {
        return (string) app(Settings::class)->get('grocery.scale_prefixes', Erp::user()->tenant_id);
    }

    /** Products with a scale code, of the chosen industry (or shared), with their retail price per base unit. */
    public function items()
    {
        $industry = IndustryContext::selected();

        return DB::table('products as p')->join('uoms as u', 'u.id', '=', 'p.base_uom_id')
            ->leftJoin('product_uoms as pu', fn ($j) => $j->on('pu.product_id', '=', 'p.id')->where('pu.is_base', true))
            ->leftJoin('prices as pr', fn ($j) => $j->on('pr.product_uom_id', '=', 'pu.id')->where('pr.min_qty', 1)->whereNull('pr.starts_at')
                ->whereIn('pr.price_list_id', DB::table('price_lists')->where('tenant_id', Erp::user()->tenant_id)->where('code', 'RETAIL')->select('id')))
            ->where('p.tenant_id', Erp::user()->tenant_id)->whereNotNull('p.plu')->where('p.is_active', true)
            ->when($industry, fn ($q) => $q->where(fn ($q) => $q->where('p.industry_id', $industry)->orWhereNull('p.industry_id')))
            ->orderBy('p.plu')->get(['p.plu', 'p.sku', 'p.name', 'u.code as unit', 'pr.amount_minor']);
    }
}
