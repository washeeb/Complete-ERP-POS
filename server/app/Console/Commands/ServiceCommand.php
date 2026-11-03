<?php

namespace App\Console\Commands;

use App\Domain\Service\Maintenance;
use App\Domain\Service\ServiceDesk;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/** Every five minutes at head office (service add-on): ticket deadlines (warn, escalate, close) and maintenance plans now due. */
class ServiceCommand extends Command
{
    protected $signature = 'erp:service';

    protected $description = 'Service desk deadlines and maintenance plans due';

    public function handle(ServiceDesk $desk, Maintenance $maintenance): int
    {
        if (config('erp.node.role') === 'branch' || ! ServiceDesk::enabled()) {
            return self::SUCCESS;
        }
        foreach (Tenant::all() as $tenant) {
            app(TenantContext::class)->set($tenant->id);
            $t = $desk->watch($tenant->id);
            $plans = $maintenance->duePlans($tenant->id);
            if (array_sum($t) + $plans > 0) {
                $this->info("{$tenant->id}: {$t['warned']} warned, {$t['escalated']} late, {$t['closed']} closed; {$plans} work order(s) from plans.");
            }
        }

        return self::SUCCESS;
    }
}
