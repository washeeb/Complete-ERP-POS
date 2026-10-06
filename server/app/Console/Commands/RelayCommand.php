<?php

namespace App\Console\Commands;

use App\Domain\Channels\ChannelSync;
use App\Domain\Channels\LiveRelay;
use App\Domain\Channels\WebhookIntake;
use App\Models\SalesChannel;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Delivers committed changes: live updates to tills/back office/storefronts, pushes to stores,
 * and turns stored webhooks into orders. Scheduled every minute; each run loops for ~55 s checking
 * every second, so changes reach screens and stores within seconds (spec §5).
 */
class RelayCommand extends Command
{
    protected $signature = 'erp:relay {--once : One pass, then exit} {--seconds=55}';

    protected $description = 'Deliver live updates, store pushes and incoming store orders';

    public function handle(LiveRelay $live, ChannelSync $sync, WebhookIntake $webhooks): int
    {
        $until = microtime(true) + (int) $this->option('seconds');
        do {
            $work = $live->run();
            foreach (SalesChannel::withoutGlobalScopes()->where('status', 'active')->get() as $channel) {
                app(TenantContext::class)->set($channel->tenant_id);
                $work += $webhooks->process($channel);
                if ($channel->type !== 'storefront') {
                    $work += $sync->dispatch($channel);
                    $work += $sync->runDue($channel)['done'];
                }
            }
            if ($this->option('once')) {
                break;
            }
            if ($work === 0) {
                usleep(1_000_000);
            }
        } while (microtime(true) < $until);

        return self::SUCCESS;
    }
}
