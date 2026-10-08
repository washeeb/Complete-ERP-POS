<?php

namespace App\Console\Commands;

use App\Domain\Analytics\InsightAlerts;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/** Nightly insights: forecasts, stock health, margins, loss prevention and cash flow turned into alerts. No AI model needed. */
class InsightsCommand extends Command
{
    protected $signature = 'erp:insights';

    protected $description = 'Refresh the insight alerts (running out, expiry, margins, loss prevention, cash shortfall)';

    public function handle(InsightAlerts $alerts): int
    {
        foreach (Tenant::all() as $tenant) {
            app(TenantContext::class)->set($tenant->id);
            $open = $alerts->run($tenant->id);
            $this->info("{$tenant->name}: ".array_sum($open).' open alert(s).');
        }

        return self::SUCCESS;
    }
}
