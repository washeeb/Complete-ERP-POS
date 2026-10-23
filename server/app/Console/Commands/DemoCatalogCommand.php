<?php

namespace App\Console\Commands;

use App\Domain\Catalog\CatalogService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Brand;
use App\Models\Category;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\TaxGroup;
use App\Models\Uom;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Caps;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Optional sample data (setup wizard "sample data" step, spec §3.3): a small Ghanaian grocery and
 * wholesale range with prices and opening stock in every warehouse. Clearly fictional barcodes
 * (prefix 200 = in-store use). Runs as a named person (--as) so the audit log is truthful.
 */
class DemoCatalogCommand extends Command
{
    protected $signature = 'erp:demo-catalog {--as= : Email of the person loading the sample data}';

    protected $description = 'Load sample products, prices and opening stock (for demos and training only)';

    /** [sku, name, category, brand, retail, carton factor, carton price, wholesale piece, tax, opening pcs] */
    private const ITEMS = [
        ['RICE-5KG', 'Royal Aroma rice 5kg', 'Rice & grains', 'Royal Aroma', '145.00', 4, '560.00', '138.00', 'STD', 40],
        ['RICE-25KG', 'Royal Aroma rice 25kg', 'Rice & grains', 'Royal Aroma', '690.00', null, null, '665.00', 'STD', 12],
        ['GARI-1KG', 'Gari 1kg', 'Rice & grains', null, '18.00', null, null, null, 'EXEMPT', 60],
        ['OIL-FRY-1L', 'Frytol vegetable oil 1L', 'Cooking oil', 'Frytol', '42.00', 12, '480.00', '40.00', 'STD', 72],
        ['OIL-FRY-5L', 'Frytol vegetable oil 5L', 'Cooking oil', 'Frytol', '195.00', 4, '760.00', '188.00', 'STD', 20],
        ['TOM-400', 'Gino tomato paste 400g', 'Canned & paste', 'Gino', '19.50', 24, '444.00', '18.50', 'STD', 96],
        ['TOM-70', 'Gino tomato paste 70g sachet', 'Canned & paste', 'Gino', '3.00', 50, '140.00', null, 'STD', 300],
        ['SARD-125', 'Titus sardines 125g', 'Canned & paste', 'Titus', '16.00', 50, '760.00', '15.20', 'STD', 150],
        ['MILO-400', 'Milo 400g tin', 'Beverages', 'Nestlé', '58.00', 12, '672.00', '56.00', 'STD', 48],
        ['MILK-EV-160', 'Ideal evaporated milk 160g', 'Beverages', 'Ideal', '9.50', 48, '432.00', '9.00', 'STD', 192],
        ['WATER-500', 'Voltic water 500ml', 'Beverages', 'Voltic', '3.50', 24, '72.00', null, 'STD', 240],
        ['MALT-330', 'Malta Guinness 330ml', 'Beverages', 'Guinness', '12.00', 24, '270.00', '11.25', 'STD', 120],
        ['SUGAR-1KG', 'Sugar 1kg', 'Baking & sugar', null, '20.00', 10, '190.00', '19.00', 'STD', 80],
        ['FLOUR-1KG', 'Golden Penny flour 1kg', 'Baking & sugar', 'Golden Penny', '22.00', 10, '210.00', null, 'STD', 50],
        ['BREAD-SLC', 'Sliced bread', 'Bakery', null, '25.00', null, null, null, 'EXEMPT', 30],
        ['EGG-CRATE', 'Eggs, crate of 30', 'Fresh', null, '75.00', null, null, '72.00', 'EXEMPT', 25],
        ['SOAP-KEY', 'Key soap bar', 'Household', 'Key', '8.00', 36, '270.00', '7.50', 'STD', 144],
        ['DETERG-1KG', 'Omo detergent 1kg', 'Household', 'Omo', '48.00', 12, '552.00', '46.00', 'STD', 36],
        ['TISSUE-4', 'Toilet tissue, 4 rolls', 'Household', null, '22.00', 12, '252.00', null, 'STD', 48],
        ['NOODLE-70', 'Indomie noodles 70g', 'Noodles & pasta', 'Indomie', '5.00', 40, '190.00', '4.75', 'STD', 400],
        ['SPAG-500', 'Spaghetti 500g', 'Noodles & pasta', null, '13.00', 20, '250.00', null, 'STD', 100],
    ];

    /**
     * Sample medicines (pharmacy features to try: batches and expiry on the till, dosage labels, refill reminders,
     * prescription rules). [sku, name, generic, strength, form, schedule, retail, dosage, used a day, opening, months to expiry]
     * One batch expires within 90 days so the till shows the amber "expires soon".
     */
    private const MEDICINES = [
        ['PARA-500', 'Paracetamol 500mg tablets (strip of 10)', 'PARACETAMOL', '500 mg', 'Tablet', 'otc', '5.00', '2 TABLETS 3 TIMES DAILY AFTER MEALS FOR 3 DAYS', null, 200, 24],
        ['AMLO-5', 'Amlodipine 5mg tablets (pack of 28)', 'AMLODIPINE', '5 mg', 'Tablet', 'pom', '35.00', '1 TABLET ONCE DAILY', '0.036', 60, 18],
        ['METF-500', 'Metformin 500mg tablets (pack of 56)', 'METFORMIN', '500 mg', 'Tablet', 'pom', '28.00', '1 TABLET TWICE DAILY WITH MEALS', '0.036', 60, 2],
        ['AMOX-500', 'Amoxicillin 500mg capsules (pack of 21)', 'AMOXICILLIN', '500 mg', 'Capsule', 'pom', '24.00', '1 CAPSULE 3 TIMES DAILY FOR 7 DAYS', null, 80, 12],
        ['ORS-SACH', 'Oral rehydration salts sachet', 'ORAL REHYDRATION SALTS', '20.5 g', 'Powder', 'otc', '3.00', 'DISSOLVE 1 SACHET IN 1 LITRE OF CLEAN WATER', null, 150, 30],
    ];

    public function handle(CatalogService $catalog, InventoryService $inventory): int
    {
        $user = User::where('email', strtolower((string) $this->option('as')))->first();
        if (! $user) {
            $this->error('Pass --as=<email> of the person loading the sample data (they need catalog, pricing and stock rights).');

            return self::FAILURE;
        }
        app(TenantContext::class)->set($user->tenant_id);
        $actor = new Actor($user->id, $user->name, 'cli', $user->tenant_id, user: $user);
        if (Product::exists() && ! $this->confirm('This company already has products. Add the sample range anyway (existing SKUs are skipped)?', false)) {
            return self::SUCCESS;
        }

        $pc = Uom::where('code', 'PC')->firstOrFail();
        $ctn = Uom::where('code', 'CTN')->firstOrFail();
        $retail = PriceList::where('code', 'RETAIL')->first() ?? PriceList::where('is_default', true)->firstOrFail();
        $wholesale = PriceList::where('code', 'WHOLESALE')->first();
        $warehouses = Warehouse::where('is_active', true)->get();
        $n = 0;

        try {
            foreach (self::ITEMS as $i => [$sku, $name, $cat, $brand, $price, $factor, $ctnPrice, $wsPrice, $tax, $opening]) {
                if (Product::where('sku', $sku)->exists()) {
                    continue;
                }
                $category = Category::firstWhere('name', Caps::upper($cat)) ?? $catalog->saveSimple($actor, new Category, ['name' => $cat, 'is_active' => true]);
                $brandModel = $brand ? (Brand::firstWhere('name', Caps::upper($brand)) ?? $catalog->saveSimple($actor, new Brand, ['name' => $brand])) : null;
                $code = '200'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);
                $prices = [['price_list_id' => $retail->id, 'uom_id' => $pc->id, 'min_qty' => '1', 'amount' => $price]];
                $units = [];
                if ($factor) {
                    $units[] = ['uom_id' => $ctn->id, 'factor' => (string) $factor, 'barcodes' => [$code.'99']];
                    $prices[] = ['price_list_id' => $retail->id, 'uom_id' => $ctn->id, 'min_qty' => '1', 'amount' => $ctnPrice];
                }
                if ($wholesale && $wsPrice) {
                    $prices[] = ['price_list_id' => $wholesale->id, 'uom_id' => $pc->id, 'min_qty' => '1', 'amount' => $wsPrice];
                    if ($factor) {
                        $prices[] = ['price_list_id' => $wholesale->id, 'uom_id' => $ctn->id, 'min_qty' => '1', 'amount' => $ctnPrice];
                    }
                }
                $product = $catalog->saveProduct($actor, new Product, [
                    'sku' => $sku, 'name' => $name, 'category_id' => $category->id, 'brand_id' => $brandModel?->id,
                    'base_uom_id' => $pc->id, 'tax_group_id' => TaxGroup::where('code', $tax)->value('id'),
                    'track_stock' => true, 'is_active' => true, 'base_barcodes' => [$code.'00'], 'units' => $units, 'prices' => $prices,
                ], 'Sample data');
                $base = $product->units->firstWhere('is_base', true);
                foreach ($warehouses as $wh) {
                    $inventory->adjust($actor, $wh, $product, $base, (string) $opening, 'opening', '');
                }
                $n++;
            }
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Medicines with batches and expiry dates.
        try {
            $m = $this->medicines($actor, $catalog, $inventory, $pc, $retail, $warehouses);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Added {$n} sample products and {$m} sample medicines with prices and opening stock in {$warehouses->count()} warehouse(s).");

        return self::SUCCESS;
    }

    private function medicines(Actor $actor, CatalogService $catalog, InventoryService $inventory, Uom $pc, PriceList $retail, $warehouses): int
    {
        $category = Category::firstWhere('name', Caps::upper('Medicines')) ?? $catalog->saveSimple($actor, new Category, ['name' => 'Medicines', 'is_active' => true]);
        $n = 0;
        foreach (self::MEDICINES as $i => [$sku, $name, $generic, $strength, $form, $schedule, $price, $dosage, $daily, $opening, $months]) {
            if (Product::where('sku', $sku)->exists()) {
                continue;
            }
            $product = $catalog->saveProduct($actor, new Product, [
                'sku' => $sku, 'name' => $name, 'category_id' => $category->id, 'base_uom_id' => $pc->id, 'tax_group_id' => TaxGroup::where('code', 'EXEMPT')->value('id'),
                'track_stock' => true, 'track_batches' => true, 'is_active' => true, 'drug_schedule' => $schedule, 'generic_name' => $generic, 'strength' => $strength,
                'dosage_form' => $form, 'dosage' => $dosage, 'daily_use' => $daily, 'base_barcodes' => ['2009'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT).'00'],
                'prices' => [['price_list_id' => $retail->id, 'uom_id' => $pc->id, 'min_qty' => '1', 'amount' => $price]],
            ], 'Sample data');
            $base = $product->units->firstWhere('is_base', true);
            foreach ($warehouses as $wh) { // every store, so any test till can sell them
                $inventory->adjust($actor, $wh, $product, $base, (string) $opening, 'opening', '', null, null, 'B'.now()->format('ym').'-'.($i + 1),
                    now()->addMonths($months)->endOfMonth()->toDateString());
            }
            $n++;
        }

        return $n;
    }
}
