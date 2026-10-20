<?php

namespace App\Console\Commands;

use App\Domain\Remote\DeviceHealth;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/** Tell IT about tills with problems (offline while trading, stuck sales, storage, old version): once a day per problem. */
class DeviceHealthAlertsCommand extends Command
{
    protected $signature = 'erp:device-health';

    protected $description = 'Notify IT about devices with problems (offline during trading hours, stuck sales, storage nearly full, old version)';

    public function handle(DeviceHealth $health): int
    {
        foreach (Tenant::all() as $tenant) {
            app(TenantContext::class)->set($tenant->id);
            $n = $health->alert($tenant->id);
            $this->info("{$tenant->name}: {$n} notification(s).");
        }

        return self::SUCCESS;
    }
}
