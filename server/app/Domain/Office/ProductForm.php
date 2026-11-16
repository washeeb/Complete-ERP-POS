<?php

namespace App\Domain\Office;

use App\Domain\Ai\AiGateway;
use App\Domain\Catalog\CatalogService;
use App\Domain\Modules\ModuleService;
use App\Domain\Support\Actor;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\Erp;
use App\Models\Brand;
use App\Models\Category;
use App\Models\HealthReading;
use App\Models\Industry;
use App\Models\KitchenStation;
use App\Models\ModifierGroup;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\TaxGroup;
use App\Models\Uom;
use App\Models\User;
use App\Support\IndustryContext;
use App\Support\Money;

/**
 * The product form in the new back office (release 3, part 2): the current form's fields and module sections (medicine,
 * serial numbers, returnable containers, consignment, health check, services and packs, restaurant), units and barcodes,
 * and prices. It fills from the product and saves through CatalogService::saveProduct, exactly as the current form does,
 * so both forms keep the same rules, permissions and audit trail.
 */
final class ProductForm
{
    public function __construct(private readonly User $user) {}

    private function currency(): string
    {
        return $this->user->tenant?->currency_code ?? 'GHS';
    }

    /** The form for a new product (null) or an existing one: its values, the choices, and which sections show. */
    public function form(?Product $p): array
    {
        $currency = $this->currency();
        $exp = Money::exponent($currency);
        $opt = fn ($rows) => collect($rows)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
        $uoms = Uom::whereNull('merged_into_id')->orderBy('code')->get()->mapWithKeys(fn ($u) => [$u->id => "{$u->code} · {$u->name}"]);
        $industries = Industry::licensed()->exists();
        $industryChoices = IndustryContext::choices($this->user) + ($p?->exists && $p->industry_id === null ? [IndustryContext::SHARED => 'Shared by all industries'] : []);
        $ownIds = array_keys(array_diff_key($industryChoices, [IndustryContext::SHARED => true]));
        $prices = $p ? Erp::canForIndustry('pricing.manage', $p->industry_id) : Erp::canAnywhere('pricing.manage');
        $moved = (bool) $p?->hasMoved();

        $values = $p ? $this->values($p, $exp) : [
            'name' => '', 'industry_id' => $industries ? (IndustryContext::selected() ?? (count($ownIds) === 1 ? $ownIds[0] : '')) : '', 'location' => '',
            'category_id' => '', 'brand_id' => '', 'tax_group_id' => (string) TaxGroup::where('code', 'STD')->value('id'),
            'base_uom_id' => (string) Uom::where('code', 'PC')->value('id'), 'buying_uom_id' => '', 'track_stock' => true, 'is_active' => true, 'track_batches' => false,
            'plu' => '', 'description' => '', 'drug_schedule' => '', 'generic_name' => '', 'strength' => '', 'dosage_form' => '', 'fda_reg_no' => '',
            'active_ingredients' => [], 'dosage' => '', 'nhis_price' => '', 'daily_use' => '', 'track_serials' => false, 'warranty_months' => '',
            'is_container' => false, 'deposit_product_id' => '', 'deposit_per_unit' => '1', 'consignor_id' => '', 'consignment_bp' => '', 'reading_kind' => '',
            'service_minutes' => '', 'commission_bp' => '', 'licence_keys' => false, 'pack' => ['items' => [], 'valid_days' => ''], 'station' => '',
            'modifier_group_ids' => [], 'recipe' => [], 'base_barcodes' => [], 'units' => [],
            'prices' => [['price_list_id' => (string) PriceList::where('is_default', true)->value('id'), 'uom_id' => (string) Uom::where('code', 'PC')->value('id'),
                'min_qty' => '1', 'amount' => '', 'starts_at' => '', 'ends_at' => '']],
            'reason' => '',
        ];
        $mod = fn (string $m) => ModuleService::enabledAnywhere($m);

        return [
            'id' => $p?->id, 'sku' => $p?->sku, 'name' => $p?->name, 'currency' => $currency, 'values' => $values,
            'sections' => [
                'industry' => $industries, 'medicine' => $mod('pharmacy'), 'serials' => $mod('electronics'), 'containers' => $mod('distribution'),
                'consignment' => $mod('fashion'), 'health' => Erp::canAnywhere('pharmacy.patients'), 'services' => $mod('services'), 'restaurant' => $mod('restaurant'),
                'prices' => $prices,
            ],
            'base_unit_locked' => $moved,
            'pricing_note' => $p && $prices ? ProductResource::pricingNote($p) : null,
            'below_cost' => $p && $prices && ProductResource::belowCost($p),
            'can' => ['quick_add' => Erp::canAnywhere('catalog.manage'), 'describe' => $p !== null && Erp::canAnywhere('catalog.manage')],
            'ai_unavailable' => $p ? app(AiGateway::class)->unavailableReason((string) $this->user->tenant_id) : null,
            'choices' => [
                'industries' => $opt($industryChoices),
                'categories' => $opt(Category::where('is_active', true)->orderBy('name')->pluck('name', 'id')),
                'brands' => $opt(Brand::orderBy('name')->pluck('name', 'id')),
                'tax_groups' => $opt(TaxGroup::orderBy('name')->pluck('name', 'id')),
                'uoms' => $opt($uoms),
                'price_lists' => $opt(PriceList::where('is_active', true)->orderByDesc('is_default')->pluck('name', 'id')),
                'schedules' => $opt(Product::SCHEDULES),
                'containers' => $mod('distribution') ? $opt(Product::where('is_container', true)->when($p, fn ($q) => $q->whereKeyNot($p->id))->orderBy('name')->pluck('name', 'id')) : [],
                'suppliers' => $mod('fashion') ? $opt(Supplier::orderBy('name')->pluck('name', 'id')) : [],
                'readings' => $opt(HealthReading::KINDS),
                'services' => $mod('services') ? $opt(Product::where('is_active', true)->whereNotNull('service_minutes')->orderBy('name')->pluck('name', 'id')) : [],
                'stations' => $mod('restaurant') ? $opt(KitchenStation::where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn ($s) => [$s->code => "{$s->code} · {$s->name}"])) : [],
                'modifier_groups' => $mod('restaurant') ? $opt(ModifierGroup::where('is_active', true)->orderBy('name')->pluck('name', 'id')) : [],
            ],
        ];
    }

    /** A product as the form shows it (money as typed: 12.50). */
    private function values(Product $p, int $exp): array
    {
        $in = CatalogService::inputOf($p);
        $s = fn ($v) => $v === null ? '' : (string) $v;

        return [
            'name' => $p->name, 'industry_id' => $p->industry_id ?? IndustryContext::SHARED, 'location' => $s($p->location),
            'category_id' => $s($p->category_id), 'brand_id' => $s($p->brand_id), 'tax_group_id' => $s($p->tax_group_id),
            'base_uom_id' => $s($p->base_uom_id), 'buying_uom_id' => $s($p->buying_uom_id),
            'track_stock' => (bool) $p->track_stock, 'is_active' => (bool) $p->is_active, 'track_batches' => (bool) $p->track_batches,
            'plu' => $s($p->plu), 'description' => $s($p->description),
            'drug_schedule' => $s($p->drug_schedule), 'generic_name' => $s($p->generic_name), 'strength' => $s($p->strength), 'dosage_form' => $s($p->dosage_form),
            'fda_reg_no' => $s($p->fda_reg_no), 'active_ingredients' => array_values((array) ($p->active_ingredients ?? [])), 'dosage' => $s($p->dosage),
            'nhis_price' => $p->nhis_price_minor === null ? '' : number_format($p->nhis_price_minor / 10 ** $exp, $exp, '.', ''),
            'daily_use' => $p->daily_use === null ? '' : ProductsScreen::qty((string) $p->daily_use),
            'track_serials' => (bool) $p->track_serials, 'warranty_months' => $s($p->warranty_months),
            'is_container' => (bool) $p->is_container, 'deposit_product_id' => $s($p->deposit_product_id),
            'deposit_per_unit' => $p->deposit_per_unit === null ? '1' : ProductsScreen::qty((string) $p->deposit_per_unit),
            'consignor_id' => $s($p->consignor_id), 'consignment_bp' => $s($p->consignment_bp), 'reading_kind' => $s($p->reading_kind),
            'service_minutes' => $s($p->service_minutes), 'commission_bp' => $s($p->commission_bp), 'licence_keys' => (bool) $p->licence_keys,
            'pack' => ['items' => array_map(fn ($i) => ['product_ids' => array_values((array) ($i['product_ids'] ?? [])), 'sessions' => $s($i['sessions'] ?? null), 'label' => $s($i['label'] ?? null)],
                (array) ($p->pack['items'] ?? [])), 'valid_days' => $s($p->pack['valid_days'] ?? null)],
            'station' => $s($p->station), 'modifier_group_ids' => array_values((array) ($p->modifier_group_ids ?? [])),
            'recipe' => $p->recipe()->get()->map(fn ($r) => ['product_uom_id' => $r->ingredient_uom_id, 'qty' => ProductsScreen::qty((string) $r->qty),
                'label' => ProductResource::ingredientLabel($r->ingredient_uom_id)])->all(),
            'base_barcodes' => $in['base_barcodes'],
            'units' => array_map(fn ($u) => ['uom_id' => $u['uom_id'], 'factor' => $u['factor'], 'is_sellable' => (bool) $u['is_sellable'],
                'is_purchasable' => (bool) $u['is_purchasable'], 'barcodes' => $u['barcodes']], $in['units']),
            'prices' => array_map(fn ($x) => ['price_list_id' => $x['price_list_id'], 'uom_id' => $x['uom_id'], 'min_qty' => $x['min_qty'], 'amount' => $x['amount'],
                'starts_at' => $x['starts_at'] ? substr($x['starts_at'], 0, 16) : '', 'ends_at' => $x['ends_at'] ? substr($x['ends_at'], 0, 16) : ''], $in['prices']),
            'reason' => '',
        ];
    }

    /**
     * What the person typed → what CatalogService::saveProduct takes. Only the sections this company uses are sent, so a
     * section that is not shown is never cleared; prices only by someone who may set them (the service checks again).
     */
    public function save(Actor $actor, Product $p, array $v): Product
    {
        $form = $this->form($p->exists ? $p : null);
        $sec = $form['sections'];
        $blank = fn ($x) => $x === null || $x === '' ? null : $x;
        $data = [
            'name' => (string) ($v['name'] ?? ''), 'location' => $blank($v['location'] ?? null), 'category_id' => $blank($v['category_id'] ?? null),
            'brand_id' => $blank($v['brand_id'] ?? null), 'tax_group_id' => $blank($v['tax_group_id'] ?? null), 'buying_uom_id' => $blank($v['buying_uom_id'] ?? null),
            'track_stock' => (bool) ($v['track_stock'] ?? false), 'is_active' => (bool) ($v['is_active'] ?? false), 'track_batches' => (bool) ($v['track_batches'] ?? false),
            'plu' => $blank($v['plu'] ?? null), 'description' => $blank($v['description'] ?? null),
            'base_barcodes' => array_values(array_filter(array_map('trim', (array) ($v['base_barcodes'] ?? [])))),
            'units' => array_map(fn ($u) => ['uom_id' => (string) ($u['uom_id'] ?? ''), 'factor' => (string) ($u['factor'] ?? ''), 'is_sellable' => (bool) ($u['is_sellable'] ?? false),
                'is_purchasable' => (bool) ($u['is_purchasable'] ?? false), 'barcodes' => array_values(array_filter(array_map('trim', (array) ($u['barcodes'] ?? []))))], (array) ($v['units'] ?? [])),
        ];
        if (! $form['base_unit_locked']) {
            $data['base_uom_id'] = $blank($v['base_uom_id'] ?? null);
        }
        if ($sec['industry']) {
            $data['industry_id'] = (string) ($v['industry_id'] ?? '');
        }
        if ($sec['medicine']) {
            $data += ['drug_schedule' => $blank($v['drug_schedule'] ?? null), 'generic_name' => $blank($v['generic_name'] ?? null), 'strength' => $blank($v['strength'] ?? null),
                'dosage_form' => $blank($v['dosage_form'] ?? null), 'fda_reg_no' => $blank($v['fda_reg_no'] ?? null), 'active_ingredients' => (array) ($v['active_ingredients'] ?? []),
                'dosage' => $blank($v['dosage'] ?? null), 'daily_use' => $blank($v['daily_use'] ?? null),
                'nhis_price_minor' => ($n = $blank($v['nhis_price'] ?? null)) === null ? null : Money::toMinor((string) $n, $this->currency())];
        }
        if ($sec['serials']) {
            $data += ['track_serials' => (bool) ($v['track_serials'] ?? false), 'warranty_months' => $blank($v['warranty_months'] ?? null)];
        }
        if ($sec['containers']) {
            $data += ['is_container' => (bool) ($v['is_container'] ?? false), 'deposit_product_id' => $blank($v['deposit_product_id'] ?? null), 'deposit_per_unit' => $blank($v['deposit_per_unit'] ?? null)];
        }
        if ($sec['consignment']) {
            $data += ['consignor_id' => $blank($v['consignor_id'] ?? null), 'consignment_bp' => $blank($v['consignment_bp'] ?? null)];
        }
        if ($sec['health']) {
            $data['reading_kind'] = $blank($v['reading_kind'] ?? null);
        }
        if ($sec['services']) {
            $data += ['service_minutes' => $blank($v['service_minutes'] ?? null), 'commission_bp' => $blank($v['commission_bp'] ?? null),
                'licence_keys' => (bool) ($v['licence_keys'] ?? false), 'pack' => ['items' => (array) ($v['pack']['items'] ?? []), 'valid_days' => $v['pack']['valid_days'] ?? null]];
        }
        if ($sec['restaurant']) {
            $data += ['station' => $blank($v['station'] ?? null), 'modifier_group_ids' => (array) ($v['modifier_group_ids'] ?? []),
                'recipe' => array_map(fn ($r) => ['product_uom_id' => $r['product_uom_id'] ?? null, 'qty' => (string) ($r['qty'] ?? '')], (array) ($v['recipe'] ?? []))];
        }
        if ($sec['prices']) {
            $data['prices'] = array_map(fn ($r) => ['price_list_id' => (string) ($r['price_list_id'] ?? ''), 'uom_id' => (string) ($r['uom_id'] ?? ''),
                'min_qty' => (string) (($r['min_qty'] ?? '') === '' ? '1' : $r['min_qty']), 'amount' => (string) ($r['amount'] ?? ''),
                'starts_at' => $blank($r['starts_at'] ?? null), 'ends_at' => $blank($r['ends_at'] ?? null)], (array) ($v['prices'] ?? []));
        }

        return app(CatalogService::class)->saveProduct($actor, $p, $data, $p->exists ? $blank(trim((string) ($v['reason'] ?? ''))) : null);
    }

    /** Ingredients for a recipe: products in stock, by unit. */
    public static function ingredients(string $q): array
    {
        return collect(ProductResource::ingredientOptions($q))->map(fn ($l, $id) => ['id' => (string) $id, 'label' => $l])->values()->all();
    }
}
