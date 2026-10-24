<?php

namespace App\Console\Commands;

use App\Domain\Catalog\CatalogService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Support\Actor;
use App\Models\Category;
use App\Models\Device;
use App\Models\Price;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\TaxGroup;
use App\Models\Till;
use App\Models\Uom;
use App\Models\User;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A test company at a real client's size (speed sweep, owner request 2026-10-24): adds products (with prices and opening
 * stock, batch-tracked like a pharmacy's) up to the number asked for, then till sales spread over the past days, so
 * screens can be tried and timed (erp:page-timings) with the volumes clients have. Test and demo systems only.
 */
class DemoScaleCommand extends Command
{
    protected $signature = 'erp:demo-scale {--as= : E-mail of the person loading it} {--products=1500 : Products in the company afterwards} {--sales=2000 : Till sales to add} {--days=60 : Spread over the last days}';

    protected $description = 'Fill a test company to a client\'s size: products, stock and sales history (never on a live system)';

    private const WORDS = ['Amlo', 'Metro', 'Cipro', 'Para', 'Ibu', 'Diclo', 'Lora', 'Ceti', 'Omep', 'Panto', 'Ator', 'Simva', 'Losar', 'Enala', 'Nife', 'Gluco',
        'Insu', 'Salbu', 'Pred', 'Hydro', 'Ampi', 'Amoxi', 'Doxy', 'Azith', 'Clari', 'Fluco', 'Itra', 'Keto', 'Mico', 'Vita', 'Zinc', 'Ferro', 'Folic', 'Calci', 'Magne'];

    private const ENDS = ['dipine', 'nidazole', 'floxacin', 'cetamol', 'profen', 'fenac', 'tadine', 'rizine', 'prazole', 'statin', 'tan', 'pril', 'ide', 'formin', 'mol'];

    public function handle(CatalogService $catalog, InventoryService $inventory, SaleService $sales, Authorizer $authz): int
    {
        if (app()->isProduction()) {
            $this->error('This fills a company with made-up data: never on a live system.');

            return self::FAILURE;
        }
        $user = User::where('email', strtolower((string) $this->option('as')))->first();
        if (! $user) {
            $this->error('Pass --as=<email> of the person loading it (they need catalog, pricing and stock rights).');

            return self::FAILURE;
        }
        app(TenantContext::class)->set($user->tenant_id);
        auth()->login($user);
        $actor = Actor::user($user, 'cli');
        $want = (int) $this->option('products');
        $have = Product::count();
        $pc = Uom::where('code', 'PC')->firstOrFail();
        $retail = PriceList::where('is_default', true)->firstOrFail();
        $tax = TaxGroup::where('code', 'EXEMPT')->value('id') ?? TaxGroup::value('id');
        $category = Category::firstWhere('name', 'SAMPLE RANGE') ?? $catalog->saveSimple($actor, new Category, ['name' => 'Sample range', 'is_active' => true]);
        $warehouses = Warehouse::where('is_active', true)->get();

        $this->info('Products: '.$have.' now, adding '.max(0, $want - $have).'…');
        $bar = $this->output->createProgressBar(max(0, $want - $have));
        for ($i = $have; $i < $want; $i++) {
            $name = self::WORDS[$i % count(self::WORDS)].self::ENDS[intdiv($i, count(self::WORDS)) % count(self::ENDS)].' '.(5 * (1 + $i % 20)).'mg '.(['tabs', 'caps', 'syrup', 'cream'][$i % 4]);
            $p = $catalog->saveProduct($actor, new Product, ['sku' => 'SCALE-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'name' => $name, 'category_id' => $category->id,
                'base_uom_id' => $pc->id, 'tax_group_id' => $tax, 'track_stock' => true, 'track_batches' => true, 'is_active' => true,
                'base_barcodes' => ['29'.str_pad((string) $i, 10, '0', STR_PAD_LEFT).'0'],
                'prices' => [['price_list_id' => $retail->id, 'uom_id' => $pc->id, 'min_qty' => '1', 'amount' => number_format(2 + ($i % 97) * 0.75, 2, '.', '')]]], 'Sample data');
            $base = $p->units->firstWhere('is_base', true);
            foreach ($warehouses as $w) {
                if ($i % 3 !== 0) { // a third of the range is out of stock in each store, as in real life
                    $inventory->adjust($actor, $w, $p, $base, (string) (5 + $i % 60), 'opening', '', null, '1.00', 'S'.$i, now()->addMonths(1 + $i % 30)->endOfMonth()->toDateString());
                }
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        // Till sales, posted through the real sale service, then dated over the past days.
        $device = Device::where('status', 'active')->where('kind', 'pos')->whereNotNull('till_id')->first();
        $cashier = $device ? User::where('is_active', true)->get()->first(fn ($u) => $authz->allows($u, 'pos.access', Till::find($device->till_id))) : null;
        if (! $device || ! $cashier) {
            $this->warn('No enrolled till with a cashier: products only.');

            return self::SUCCESS;
        }
        $shift = app(ShiftService::class)->current($device) ?? app(ShiftService::class)->open($device, $cashier, 0);
        $stocked = DB::table('stock_levels')->where('warehouse_id', Till::find($device->till_id)->warehouse_id)->where('on_hand', '>', 5)->pluck('product_id')->all();
        $prices = Price::with('productUom.product.taxGroup.rates')->where('price_list_id', $retail->id)->whereHas('productUom', fn ($q) => $q->whereIn('product_id', $stocked))->limit(500)->get();
        $seq = (int) DB::table('sales')->where('device_id', $device->id)->max('device_seq');
        $n = (int) $this->option('sales');
        $days = max(1, (int) $this->option('days'));
        $this->info("Sales: adding {$n} over {$days} day(s)…");
        $bar = $this->output->createProgressBar($n);
        for ($s = 0; $s < $n; $s++) {
            $lines = $engine = [];
            foreach ($prices->random(min(1 + $s % 4, $prices->count())) as $k => $pr) {
                $engine[] = ['key' => (string) $k, 'product_id' => $pr->productUom->product_id, 'category_id' => null, 'qty_milli' => 1000, 'unit_price_minor' => $pr->amount_minor,
                    'tax' => $pr->productUom->product->taxGroup?->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all() ?? [], 'manual' => null];
                $lines[] = ['product_id' => $pr->productUom->product_id, 'product_uom_id' => $pr->product_uom_id, 'qty' => '1', 'unit_price_minor' => $pr->amount_minor];
            }
            $calc = PromotionEngine::compute(['lines' => $engine, 'inclusive' => (bool) $retail->prices_include_tax, 'coupon' => null, 'sale_discount_bp' => 0, 'promotions' => []]);
            foreach ($calc['lines'] as $k => $r) {
                $lines[$k]['line_total_minor'] = $r['total'];
            }
            try {
                $sale = $sales->post($device, $cashier, ['id' => (string) Str::uuid7(), 'device_seq' => ++$seq, 'occurred_at' => now()->toIso8601String(), 'price_list_id' => $retail->id,
                    'shift_id' => $shift->id, 'lines' => $lines, 'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $calc['total']],
                    'payments' => [['method' => ['cash', 'cash', 'momo', 'card'][$s % 4], 'provider' => $s % 4 === 2 ? 'mtn' : null, 'amount_minor' => $calc['total'],
                        'tendered_minor' => $calc['total'], 'reference' => $s % 4 >= 2 ? 'T'.$s.'XYZ' : null]]])['sale'];
                $when = now()->subDays($s % $days)->setTime(8 + $s % 12, ($s * 7) % 60);
                DB::table('sales')->where('id', $sale->id)->update(['occurred_at' => $when]);
                DB::table('stock_movements')->where('source_type', 'sale')->where('source_id', $sale->id)->update(['occurred_at' => $when]);
            } catch (\Throwable $e) {
                // stock ran out on a line or similar: skip it, this is sample data
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->info(sprintf('Now: %s products, %s sales, %s stock movements.', number_format(Product::count()), number_format(DB::table('sales')->count()), number_format(DB::table('stock_movements')->count())));

        return self::SUCCESS;
    }
}
