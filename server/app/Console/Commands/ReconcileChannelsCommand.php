<?php

namespace App\Console\Commands;

use App\Domain\Channels\Reconciler;
use App\Models\SalesChannel;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/** Every 15 minutes: compare each store with the ERP and re-push anything that drifted (spec §9 reference). */
class ReconcileChannelsCommand extends Command
{
    protected $signature = 'erp:channels:reconcile';

    protected $description = 'Compare online stores with the ERP and repair differences';

    public function handle(Reconciler $reconciler): int
    {
        foreach (SalesChannel::withoutGlobalScopes()->where('status', 'active')->whereNotIn('type', ['storefront', 'whatsapp'])->get() as $channel) { // WhatsApp holds no copy of the catalog
            app(TenantContext::class)->set($channel->tenant_id);
            try {
                $diffs = $reconciler->run($channel);
                $this->line("{$channel->name}: ".count($diffs).' difference(s)'.($diffs ? ' — re-pushing' : ''));
            } catch (\Throwable $e) {
                $this->error("{$channel->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
