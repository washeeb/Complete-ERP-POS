<?php

namespace App\Console\Commands;

use App\Domain\Messaging\Notifier;
use App\Domain\Purchasing\ReorderLevels;
use App\Domain\Purchasing\SmartReorderLevels;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Support\Settings;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/** Weekly smart reorder levels from sales (--smart) and the morning low-stock message to buyers (--alerts). */
class ReorderLevelsCommand extends Command
{
    protected $signature = 'erp:reorder-levels {--smart : Work out minimum and maximum from sales} {--alerts : Tell buyers which stores have products at their minimum}';

    protected $description = 'Smart reorder levels and low-stock alerts';

    public function handle(SmartReorderLevels $smart, ReorderLevels $levels, Notifier $notifier, Settings $settings): int
    {
        foreach (Tenant::all() as $tenant) {
            app(TenantContext::class)->set($tenant->id);
            foreach (Warehouse::where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('code')->get() as $w) {
                if ($this->option('smart')) {
                    $r = $smart->recalculate($w);
                    $this->info("{$w->code}: {$r['suggested']} level(s) suggested, {$r['applied']} applied.");
                }
                if ($this->option('alerts') && $settings->get('stock.low_stock_alerts', $tenant->id) && ($n = count($levels->low($w))) > 0) {
                    $notifier->toPermission($tenant->id, ['purchasing.manage', 'stock.levels'], $w->branch_id, "{$n} product(s) at or below their minimum in {$w->name}",
                        'Purchasing → What to order turns them into draft purchase orders.', url('/admin/reorder-suggestions'));
                    $this->info("{$w->code}: {$n} low.");
                }
            }
        }

        return self::SUCCESS;
    }
}
