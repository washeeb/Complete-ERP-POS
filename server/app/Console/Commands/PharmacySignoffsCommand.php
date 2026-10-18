<?php

namespace App\Console\Commands;

use App\Domain\Messaging\Notifier;
use App\Domain\Modules\ModuleService;
use App\Domain\Pharmacy\PharmacyRules;
use App\Models\Branch;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Each evening: remind pharmacists of medicine sales to sign off, or send the day's summary (Settings → Pharmacy). */
class PharmacySignoffsCommand extends Command
{
    protected $signature = 'erp:pharmacy-signoffs';

    protected $description = 'Remind pharmacists of medicine sales made without their approval';

    public function handle(PharmacyRules $rules, Notifier $notifier, ModuleService $modules): int
    {
        foreach (Tenant::all() as $tenant) {
            app(TenantContext::class)->set($tenant->id);
            $tz = $tenant->timezone ?: 'UTC';
            foreach (Branch::where('tenant_id', $tenant->id)->where('is_active', true)->get() as $branch) {
                $after = $rules->forBranch($branch)['after'];
                if ($after === 'none' || ! $modules->isEnabled($branch, 'pharmacy')) {
                    continue;
                }
                [$from, $to] = $after === 'signoff' // waiting from the last 30 days; the summary covers today
                    ? [CarbonImmutable::now($tz)->subDays(30)->startOfDay()->utc(), CarbonImmutable::now($tz)->endOfDay()->utc()]
                    : [CarbonImmutable::now($tz)->startOfDay()->utc(), CarbonImmutable::now($tz)->endOfDay()->utc()];
                $n = PharmacyRules::unapproved($tenant->id, [$branch->id], $from->toDateTimeString(), $to->toDateTimeString())
                    ->when($after === 'signoff', fn ($q) => $q->whereNull('so.id'))->get()->count();
                if ($n === 0) {
                    continue;
                }
                $after === 'signoff'
                    ? $notifier->toPermission($tenant->id, 'pharmacy.dispense', $branch->id, "{$n} medicine sale(s) to sign off at {$branch->name}",
                        'Prescription-only or controlled medicines sold without a pharmacist. Pharmacy → Sales to sign off.', url('/admin/medicine-signoffs'))
                    : $notifier->toPermission($tenant->id, 'pharmacy.manage', $branch->id, "{$n} medicine sale(s) without a pharmacist today at {$branch->name}",
                        'Pharmacy → Sales to sign off lists them.', url('/admin/medicine-signoffs'));
                $this->info("{$branch->code}: {$n}");
            }
        }

        return self::SUCCESS;
    }
}
