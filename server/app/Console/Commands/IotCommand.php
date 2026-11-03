<?php

namespace App\Console\Commands;

use App\Domain\Iot\Clouds;
use App\Domain\Iot\Sensors;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/** Every minute at head office (sensors add-on): read sensor clouds, find silent sensors and late alarms, remind and escalate. */
class IotCommand extends Command
{
    protected $signature = 'erp:iot';

    protected $description = 'Sensors: clouds, silent sensors, alarm reminders';

    public function handle(Sensors $sensors, Clouds $clouds): int
    {
        if (config('erp.node.role') === 'branch' || ! Sensors::enabled()) {
            return self::SUCCESS;
        }
        foreach (Tenant::all() as $tenant) {
            app(TenantContext::class)->set($tenant->id);
            $read = $clouds->pollDue($tenant->id);
            $w = $sensors->watch($tenant->id);
            if ($read + array_sum($w) > 0) {
                $this->info("{$tenant->id}: {$read} reading(s) from clouds; {$w['silent']} silent, {$w['late']} late, {$w['reminded']} reminded, {$w['escalated']} escalated.");
            }
        }

        return self::SUCCESS;
    }
}
