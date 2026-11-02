<?php

namespace App\Filament\Widgets\Panels;

use App\Filament\Support\Erp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What every dashboard panel needs: the company's day (its time zone), and the branches and stores the person may see
 * for a permission (null = all of the company), so a panel never shows more than the pages behind it.
 */
trait PanelScope
{
    protected static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(Erp::user()?->tenant?->timezone ?: 'UTC')->startOfDay();
    }

    protected static function tz(): string
    {
        return Erp::user()?->tenant?->timezone ?: 'UTC';
    }

    protected static function tenantId(): string
    {
        return (string) Erp::user()?->tenant_id;
    }

    /** @return list<string>|null branches allowed for the permission; null = the whole company */
    protected static function branches(string $permission): ?array
    {
        return Erp::branchIds($permission);
    }

    /** @return list<string> stores (warehouses) in the allowed branches */
    protected static function warehouses(string $permission): array
    {
        $b = self::branches($permission);

        return DB::table('warehouses')->where('tenant_id', self::tenantId())->when($b !== null, fn ($q) => $q->whereIn('branch_id', $b))->pluck('id')->all();
    }
}
