<?php

namespace App\Console\Commands;

use App\Domain\Analytics\DemandForecaster;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Pricing\PromotionService;
use App\Domain\Reports\ReportEngine;
use App\Models\Device;
use App\Models\Price;
use App\Models\PriceList;
use App\Models\Till;
use App\Models\User;
use App\Security\Authorizer;
use App\Support\Qty;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Performance check (Phase 13): posts many till sales through the real sale service and times the heavy reads, all
 * inside one database transaction that is rolled back at the end — nothing stays behind. Needs an enrolled till, a
 * cashier who may use it, and priced products (e.g. the demo catalogue).
 */
class LoadTestCommand extends Command
{
    protected $signature = 'erp:load-test {--sales=300 : Sales to post} {--lines=3 : Lines per sale} {--force : Allow on a production system}';

    protected $description = 'Measure sale posting and report speed (rolled back: leaves no data)';

    public function handle(SaleService $sales, Authorizer $authz): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('This is a live system: add --force to run it anyway (it is rolled back, but it loads the server).');

            return self::FAILURE;
        }
        $device = Device::withoutGlobalScopes()->where('status', 'active')->where('kind', 'pos')->whereNotNull('till_id')->first();
        if (! $device) {
            $this->error('No enrolled till found.');

            return self::FAILURE;
        }
        app(TenantContext::class)->set($device->tenant_id);
        $till = Till::findOrFail($device->till_id);
        $cashier = User::where('is_active', true)->get()->first(fn ($u) => $authz->allows($u, 'pos.access', $till));
        $list = PriceList::where('is_default', true)->firstOrFail();
        $prices = Price::with('productUom.product.taxGroup.rates')->where('price_list_id', $list->id)->where('min_qty', '<=', 1)->whereNull('ends_at')->limit(200)->get()
            ->filter(fn ($p) => $p->productUom?->product?->is_active && ! $p->productUom->product->is_container)->values();
        if (! $cashier || $prices->count() < 3) {
            $this->error('Needs a cashier with till access and at least 3 priced products (php artisan erp:demo-catalog).');

            return self::FAILURE;
        }
        $n = (int) $this->option('sales');
        $per = max(1, (int) $this->option('lines'));
        $times = [];
        $reads = [];
        DB::beginTransaction();
        try {
            $shift = app(ShiftService::class)->current($device) ?? app(ShiftService::class)->open($device, $cashier, 0);
            $promos = PromotionService::active($till->branch_id, $list->id, now()); // the server prices with the running promotions
            $seq = (int) DB::table('sales')->where('device_id', $device->id)->max('device_seq');
            for ($i = 0; $i < $n; $i++) {
                $lines = [];
                $engine = [];
                foreach ($prices->random(min($per, $prices->count())) as $k => $p) {
                    $qty = '1'; // one of each: no quantity price tiers
                    $engine[] = ['key' => (string) $k, 'product_id' => $p->productUom->product_id, 'category_id' => null, 'qty_milli' => Qty::toMilli($qty), 'unit_price_minor' => $p->amount_minor,
                        'tax' => $p->productUom->product->taxGroup?->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all() ?? [], 'manual' => null];
                    $lines[] = ['product_id' => $p->productUom->product_id, 'product_uom_id' => $p->product_uom_id, 'qty' => $qty, 'unit_price_minor' => $p->amount_minor];
                }
                $calc = PromotionEngine::compute(['lines' => $engine, 'inclusive' => (bool) $list->prices_include_tax, 'coupon' => null, 'sale_discount_bp' => 0, 'promotions' => $promos]);
                foreach ($calc['lines'] as $k => $r) {
                    $lines[$k]['line_total_minor'] = $r['total'];
                }
                $t = hrtime(true);
                $sales->post($device, $cashier, ['id' => (string) Str::uuid7(), 'device_seq' => ++$seq, 'occurred_at' => now()->toIso8601String(), 'price_list_id' => $list->id,
                    'shift_id' => $shift->id, 'lines' => $lines, 'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $calc['total']],
                    'payments' => [['method' => 'cash', 'amount_minor' => $calc['total'], 'tendered_minor' => $calc['total']]]]);
                $times[] = (hrtime(true) - $t) / 1e6;
            }
            $owner = User::where('is_active', true)->get()->first(fn ($u) => $authz->branchIds($u, 'sales.view') === null) ?? $cashier;
            foreach ([
                'Report: sales by product this month' => fn () => app(ReportEngine::class)->run($owner, 'sales_lines', ['dimensions' => ['product'], 'measures' => ['qty', 'net']]),
                'Report: receipts by hour' => fn () => app(ReportEngine::class)->run($owner, 'receipts', ['dimensions' => ['hour'], 'measures' => ['count', 'total'], 'period' => 'today']),
                'Forecast for the till\'s warehouse' => fn () => app(DemandForecaster::class)->forWarehouse($till->warehouse_id),
            ] as $what => $read) {
                $t = hrtime(true);
                $read();
                $reads[$what] = (hrtime(true) - $t) / 1e6;
            }
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        DB::rollBack();

        sort($times);
        $pct = fn (float $p) => $times[(int) min(count($times) - 1, floor($p * count($times)))];
        $total = array_sum($times) / 1000;
        $this->table(['Measure', 'Result'], [
            ['Sales posted', "{$n} × {$per} lines"],
            ['Throughput', number_format($n / max($total, 0.001), 1).' sales/second (one till, one connection)'],
            ['Posting time p50 / p95 / max', sprintf('%.0f / %.0f / %.0f ms', $pct(0.5), $pct(0.95), end($times))],
            ...array_map(fn ($k, $v) => [$k, sprintf('%.0f ms', $v)], array_keys($reads), $reads),
            ['Data left behind', 'none (rolled back)'],
        ]);

        return self::SUCCESS;
    }
}
