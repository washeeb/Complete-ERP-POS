<?php

namespace App\Console\Commands;

use App\Domain\Services\MembershipService;
use App\Domain\Support\Actor;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/** Invoices every membership period that has started (and any missed while the server was off). Head office only. */
class BillMembershipsCommand extends Command
{
    protected $signature = 'erp:bill-memberships {--date= : Bill periods starting up to this date (default today)}';

    protected $description = 'Raise the invoices (and session passes) for membership periods that have started';

    public function handle(MembershipService $memberships): int
    {
        if (config('erp.node.role') === 'branch') {
            $this->info('Branch server: head office bills memberships; the invoices arrive by sync.');

            return self::SUCCESS;
        }
        $n = 0;
        foreach (Tenant::all() as $tenant) {
            app(TenantContext::class)->set($tenant->id);
            $n += $memberships->billDue(Actor::system($tenant->id, 'Membership billing', 'system'), $this->option('date'));
        }
        $this->info("{$n} membership invoice(s) raised.");

        return self::SUCCESS;
    }
}
