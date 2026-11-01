<?php

namespace App\Console\Commands;

use App\Domain\Purchasing\SupplierOnboarding;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/** Daily: supplier documents about to expire, or expired, go to the bell of the people who approve suppliers and buy. */
class SupplierDocumentsCommand extends Command
{
    protected $signature = 'erp:supplier-documents';

    protected $description = 'Tell buyers about supplier documents expiring soon or expired (runs daily)';

    public function handle(SupplierOnboarding $onboarding): int
    {
        if (config('erp.node.role') === 'branch') { // once, at head office
            $this->info('Supplier documents are watched at head office.');

            return self::SUCCESS;
        }
        foreach (Tenant::all() as $tenant) {
            app(TenantContext::class)->set($tenant->id);
            $this->info("{$tenant->name}: ".$onboarding->expiryAlerts($tenant->id).' alert(s).');
        }

        return self::SUCCESS;
    }
}
