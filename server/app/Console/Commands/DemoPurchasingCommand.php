<?php

namespace App\Console\Commands;

use App\Domain\Inventory\BinService;
use App\Domain\Purchasing\ReorderService;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Bin;
use App\Models\Branch;
use App\Models\Price;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ReorderRule;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Caps;
use App\Support\Money;
use App\Support\Qty;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Sample suppliers, bins at head office and reorder levels, on top of erp:demo-catalog (demos and training only). */
class DemoPurchasingCommand extends Command
{
    protected $signature = 'erp:demo-purchasing {--as= : email of the person loading the sample data}';

    protected $description = 'Load sample suppliers, head-office bins and reorder levels (for demos and training only)';

    /** Fictitious suppliers and the product categories they carry. */
    private const SUPPLIERS = [
        ['ACCRA-DIST', 'Accra Wholesale Distributors (sample)', 30, 3, ['Beverages', 'Canned & paste', 'Noodles & pasta']],
        ['TEMA-FOODS', 'Tema Foods Ltd (sample)', 14, 2, ['Rice & grains', 'Cooking oil', 'Fresh']],
        ['KSI-HOUSE', 'Kumasi Household Supplies (sample)', 0, 5, ['Household', 'Personal care']],
    ];

    private const BINS = [['A-01', 10], ['A-02', 20], ['A-03', 30], ['B-01', 40], ['B-02', 50], ['B-03', 60]];

    public function handle(SupplierService $suppliers, BinService $bins, ReorderService $reorder): int
    {
        $user = User::where('email', strtolower((string) $this->option('as')))->first();
        if (! $user) {
            $this->error('Pass --as=<email> of the person loading the sample data.');

            return self::FAILURE;
        }
        app(TenantContext::class)->set($user->tenant_id);
        $actor = new Actor($user->id, $user->name, 'cli', $user->tenant_id, user: $user);
        $retail = PriceList::where('code', 'RETAIL')->first();

        try {
            foreach (self::SUPPLIERS as [$code, $name, $terms, $lead, $categories]) {
                if (Supplier::where('code', $code)->exists()) {
                    continue;
                }
                $lines = [];
                foreach (Product::whereHas('category', fn ($q) => $q->whereIn('name', array_map([Caps::class, 'upper'], $categories)))->with('units')->get() as $p) {
                    $unit = $p->units->firstWhere('is_base', false) ?? $p->units->firstWhere('is_base', true);
                    $sell = Price::where('product_id', $p->id)->where('product_uom_id', $unit->id)->where('price_list_id', $retail?->id)->value('amount_minor');
                    $lines[] = ['product_uom_id' => $unit->id, 'supplier_sku' => $code.'-'.$p->sku, 'is_preferred' => true, 'min_order_qty' => '1',
                        'last_cost' => $sell ? Money::format(intdiv($sell * 75, 100), 'GHS') : null]; // sample cost: 75 % of the shelf price
                }
                $suppliers->save($actor, new Supplier, ['code' => $code, 'name' => $name, 'payment_terms_days' => $terms, 'lead_time_days' => $lead,
                    'phone' => '+233 30 000 0000', 'products' => $lines]);
            }

            $hq = Warehouse::whereIn('branch_id', Branch::where('is_hq', true)->pluck('id'))->orderBy('code')->first();
            if ($hq) {
                $bins->setUseBins($actor, $hq, true);
                foreach (self::BINS as [$code, $order]) {
                    if (! Bin::where('warehouse_id', $hq->id)->where('code', $code)->exists()) {
                        $bins->saveBin($actor, new Bin, ['warehouse_id' => $hq->id, 'code' => $code, 'pick_order' => $order]);
                    }
                }
                foreach (Product::where('track_stock', true)->get() as $p) {
                    if (ReorderRule::where('warehouse_id', $hq->id)->where('product_id', $p->id)->exists()) {
                        continue;
                    }
                    $have = Qty::toMilli((string) (DB::table('stock_levels')->where('warehouse_id', $hq->id)->where('product_id', $p->id)->value('on_hand') ?? '0'));
                    $max = max(12_000, $have);
                    $reorder->saveRule($actor, $hq, $p, Qty::format(intdiv($max, 4)), Qty::format($max));
                }
            }
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Sample suppliers, head-office bins and reorder levels are in place.');

        return self::SUCCESS;
    }
}
