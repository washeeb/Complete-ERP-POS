<?php

namespace App\Console\Commands;

use App\Domain\Crm\Crm;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/** Every five minutes at head office (CRM add-on): reminders of follow-ups now due, and campaigns whose time has come. */
class CrmCommand extends Command
{
    protected $signature = 'erp:crm';

    protected $description = 'CRM follow-up reminders and scheduled campaigns';

    public function handle(Crm $crm): int
    {
        if (config('erp.node.role') === 'branch' || ! Crm::enabled()) {
            return self::SUCCESS;
        }
        foreach (Tenant::all() as $tenant) {
            app(TenantContext::class)->set($tenant->id);
            $reminded = $crm->remindDue($tenant->id);
            $sent = $crm->sendDue($tenant->id);
            if ($reminded || $sent) {
                $this->info("{$tenant->id}: {$reminded} reminder(s), {$sent} campaign(s) sent.");
            }
        }

        return self::SUCCESS;
    }
}
