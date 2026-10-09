<?php

namespace App\Console\Commands;

use App\Domain\Support\SystemHealth;
use Illuminate\Console\Command;

/** The launch-checklist health checks; exit code 1 when any check fails (for monitoring tools). */
class HealthCommand extends Command
{
    protected $signature = 'erp:health {--beat : Record that the scheduler is running (used by the scheduler every minute)}';

    protected $description = 'Check the system: database, scheduler, backups, disk, settings, licence, queues';

    public function handle(SystemHealth $health): int
    {
        if ($this->option('beat')) {
            SystemHealth::beat('scheduler');

            return self::SUCCESS;
        }
        $rows = $health->run();
        $this->table(['Check', 'Status', 'Detail'], array_map(fn ($r) => [$r['check'], strtoupper($r['status']), $r['detail']], $rows));

        return collect($rows)->contains('status', 'fail') ? self::FAILURE : self::SUCCESS;
    }
}
