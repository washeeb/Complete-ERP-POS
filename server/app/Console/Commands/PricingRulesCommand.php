<?php

namespace App\Console\Commands;

use App\Domain\Pricing\PricingRules;
use App\Domain\Support\Actor;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hourly at head office: prices follow deliveries and freight posted lately — also those posted at a branch server,
 * which reach head office by sync without passing through the posting there. Repeats are harmless: a price already
 * right is left alone and a waiting suggestion is not announced twice.
 */
class PricingRulesCommand extends Command
{
    protected $signature = 'erp:pricing-rules {--hours=3 : How far back to look for deliveries and freight}';

    protected $description = 'Selling prices follow the latest costs (pricing rules)';

    public function handle(PricingRules $rules): int
    {
        if (config('erp.node.role') === 'branch') {
            return self::SUCCESS; // prices are set at head office
        }
        $since = now()->subHours(max(1, (int) $this->option('hours')));
        foreach (Tenant::all() as $tenant) {
            app(TenantContext::class)->set($tenant->id);
            if (! DB::table('pricing_rules')->where('tenant_id', $tenant->id)->where('is_active', true)->exists()) {
                continue;
            }
            $ids = DB::table('goods_receipt_lines as l')->join('goods_receipts as g', 'g.id', '=', 'l.goods_receipt_id')
                ->where('g.tenant_id', $tenant->id)->where('g.status', 'posted')->where('g.posted_at', '>=', $since)->distinct()->pluck('l.product_id')
                ->merge(DB::table('landed_cost_lines as l')->join('landed_costs as c', 'c.id', '=', 'l.landed_cost_id')
                    ->where('c.tenant_id', $tenant->id)->where('c.posted_at', '>=', $since)->distinct()->pluck('l.product_id'))
                ->unique()->values();
            if ($ids->isEmpty()) {
                continue;
            }
            $r = DB::transaction(fn () => $rules->follow(Actor::system($tenant->id, 'Pricing rules'), $ids->all(), 'recent deliveries'));
            $this->info("{$tenant->id}: {$r['applied']} price(s) set, {$r['suggested']} waiting for review.");
        }

        return self::SUCCESS;
    }
}
