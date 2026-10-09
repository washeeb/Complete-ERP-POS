<?php

namespace App\Console\Commands;

use App\Domain\Launch\PilotReport;
use App\Models\GoLivePlan;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * The evening pilot report (Phase 14): sent once a day at the go-live plan's time while the pilot runs (parallel run,
 * and the first 60 days live). Safe to run often; --now sends every running pilot's report at once.
 */
class PilotReportCommand extends Command
{
    protected $signature = 'erp:pilot-report {--now : Send now, whatever the time} {--show : Print the report instead of e-mailing it}';

    protected $description = 'E-mail the daily pilot report to the support address';

    public function handle(PilotReport $reports): int
    {
        if (config('erp.node.role') === 'branch') {
            $this->info('Branch server: head office sends the pilot report.');

            return self::SUCCESS;
        }
        $plans = $this->option('now') || $this->option('show')
            ? GoLivePlan::withoutGlobalScopes()->whereIn('status', ['parallel', 'live'])->get()->all()
            : $reports->due();
        foreach ($plans as $plan) {
            $name = Tenant::find($plan->tenant_id)?->name;
            if ($this->option('show')) {
                $r = $reports->build($plan);
                $this->line($r['title']);
                foreach ([...$r['lines'], ...array_map(fn ($w) => "! {$w}", $r['warnings'])] as $l) {
                    $this->line($l);
                }

                continue;
            }
            $r = $reports->send($plan);
            $this->info("{$name}: ".($r['sent_to'] ? "sent to {$r['sent_to']}" : 'not sent (no support e-mail address, or e-mail failed)').'.');
        }
        if (! $plans) {
            $this->info('No pilot report due.');
        }

        return self::SUCCESS;
    }
}
