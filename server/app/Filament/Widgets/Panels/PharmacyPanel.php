<?php

namespace App\Filament\Widgets\Panels;

use App\Filament\Pages\ExpiryReturns;
use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Filament\Support\Erp;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/** Dashboard: the pharmacy — prescriptions today and this month, medicines expiring within 90 days. */
class PharmacyPanel extends StatsOverviewWidget
{
    use PanelScope;

    public int|string|array $columnSpan = 'full'; // public: the dashboard sets each panel's width

    protected ?string $heading = 'Pharmacy';

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $branches = self::pharmacyBranches();
        $today = self::today();
        $rx = fn () => DB::table('prescriptions')->where('tenant_id', self::tenantId())->when($branches !== null, fn ($q) => $q->whereIn('branch_id', $branches));
        $warehouses = DB::table('warehouses')->where('tenant_id', self::tenantId())->when($branches !== null, fn ($q) => $q->whereIn('branch_id', $branches))->pluck('id');
        $expiring = (int) DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')
            ->whereIn('l.warehouse_id', $warehouses)->where('l.on_hand', '>', 0)->whereNotNull('b.expires_on')
            ->where('b.expires_on', '<=', $today->addDays(90)->toDateString())->distinct()->count('l.product_id');
        $list = PrescriptionResource::canViewAny() ? PrescriptionResource::getUrl('index') : null;

        return [
            Stat::make('Prescriptions today', (string) $rx()->where('created_at', '>=', $today->utc())->count())->url($list),
            Stat::make('Prescriptions this month', (string) $rx()->where('created_at', '>=', $today->startOfMonth()->utc())->count())->url($list),
            Stat::make('Medicines expiring in 90 days', (string) $expiring)->color($expiring ? 'warning' : 'success')
                ->url(ExpiryReturns::canAccess() ? ExpiryReturns::getUrl() : null),
        ];
    }

    /** Branches where the person dispenses or manages the pharmacy (null = all). */
    private static function pharmacyBranches(): ?array
    {
        $a = Erp::branchIds('pharmacy.dispense');
        $b = Erp::branchIds('pharmacy.manage');

        return $a === null || $b === null ? null : array_values(array_unique([...$a, ...$b]));
    }

    public static function canView(): bool
    {
        return Erp::canAnywhere('pharmacy.dispense') || Erp::canAnywhere('pharmacy.manage');
    }
}
