<?php

namespace App\Console\Commands;

use App\Domain\Api\WebhookDispatcher;
use App\Domain\Channels\ChannelSync;
use App\Domain\Channels\LiveRelay;
use App\Domain\Channels\WebhookIntake;
use App\Domain\Grocery\EslRelay;
use App\Domain\Messaging\Messenger;
use App\Domain\Messaging\NotificationRelay;
use App\Domain\Restaurant\SmsRelay;
use App\Models\SalesChannel;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Delivers committed changes: live updates to tills/back office/storefronts, pushes to stores,
 * and turns stored webhooks into orders. Scheduled every minute; each run loops for ~55 s checking
 * every second, so changes reach screens and stores within seconds (spec §5).
 */
class RelayCommand extends Command
{
    protected $signature = 'erp:relay {--once : One pass, then exit} {--seconds=55}';

    protected $description = 'Deliver live updates, store pushes and incoming store orders';

    public function handle(LiveRelay $live, ChannelSync $sync, WebhookIntake $webhooks, EslRelay $esl, SmsRelay $sms, NotificationRelay $notify, Messenger $messenger,
        WebhookDispatcher $hooks): int
    {
        $until = microtime(true) + (int) $this->option('seconds');
        do {
            $work = $live->run();
            try {
                $work += $esl->run();
            } catch (\Throwable $e) {
                Log::warning('Shelf-label prices not delivered; retrying next run', ['error' => $e->getMessage()]);
            }
            try {
                $work += $sms->run();
            } catch (\Throwable $e) {
                Log::warning('Booking SMS not delivered; retrying next run', ['error' => $e->getMessage()]);
            }
            foreach (['Notifications' => fn () => $notify->run(), 'Messages' => fn () => $messenger->deliverDue(), 'Webhooks' => fn () => $hooks->run()] as $what => $step) {
                try {
                    $work += $step();
                } catch (\Throwable $e) {
                    Log::warning("{$what} not delivered; retrying next run", ['error' => $e->getMessage()]);
                }
            }
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
