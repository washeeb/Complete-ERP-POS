<?php

namespace App\Console\Commands;

use App\Domain\Customers\Loyalty;
use App\Domain\Customers\StoredValues;
use App\Domain\Support\Actor;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/** Daily: loyalty points past their expiry and gift cards / store credit unused for the expiry period. */
class ExpireBalancesCommand extends Command
{
    protected $signature = 'erp:expire-balances';

    protected $description = 'Expire loyalty points and unused gift card / store credit balances (runs daily)';

    public function handle(Loyalty $loyalty, StoredValues $storedValues): int
    {
        if (config('erp.node.role') === 'branch') { // once, at head office; the expiries reach branches by sync
            $this->info('Expiry runs at head office.');

            return self::SUCCESS;
        }
        foreach (Tenant::all() as $tenant) {
            app(TenantContext::class)->set($tenant->id);
            $actor = Actor::system($tenant->id, 'Expiry run', 'system');
            $points = $loyalty->expireAll($actor, now());
            $cards = $storedValues->expireAll($actor, now());
            $this->info("{$tenant->name}: {$points} point(s) expired, {$cards} card balance(s) expired.");
        }

        return self::SUCCESS;
    }
}
