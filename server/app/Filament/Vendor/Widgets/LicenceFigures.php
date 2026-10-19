<?php

namespace App\Filament\Vendor\Widgets;

use App\Domain\Licensing\Vendor\VendorBilling;
use App\Filament\Vendor\Resources\Invoices\InvoiceResource;
use App\Filament\Vendor\Resources\Licenses\LicenseResource;
use App\Filament\Vendor\VendorUi;
use App\Models\Vendor\VendorLicense;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LicenceFigures extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $current = VendorLicense::whereNull('revoked_at')->whereNull('superseded_by')->get();
        $by = $current->countBy(fn (VendorLicense $l) => $l->status());
        $money = VendorBilling::figures();
        $list = fn (string $state) => LicenseResource::getUrl('index', ['filters' => ['state' => ['value' => $state]]]);

        return [
            Stat::make('Active licences', (string) (($by['active'] ?? 0) + ($by['expiring'] ?? 0)))
                ->description(($current->where('kind', 'trial')->count()).' trial(s) · '.VendorLicense::distinct('customer_id')->whereNull('revoked_at')->count('customer_id').' customers')
                ->url($list('current')),
            Stat::make('Ending within 60 days', (string) ($by['expiring'] ?? 0))->color(($by['expiring'] ?? 0) ? 'warning' : 'success')->url($list('expiring')),
            Stat::make('Ended (grace or read-only)', (string) (($by['grace'] ?? 0) + ($by['expired'] ?? 0)))->color(($by['grace'] ?? 0) + ($by['expired'] ?? 0) ? 'danger' : 'success')->url($list('expired')),
            Stat::make('Revoked', (string) VendorLicense::whereNotNull('revoked_at')->count())->url($list('revoked')),
            Stat::make('Yearly licence revenue', VendorUi::money($money['arr']))->description('Paid licences running now, per year'),
            Stat::make('Owed by customers', VendorUi::money($money['outstanding']))->description(VendorUi::money($money['overdue']).' overdue')
                ->color($money['overdue'] ? 'danger' : 'success')->url(InvoiceResource::getUrl('index', ['filters' => ['status' => ['value' => 'overdue']]])),
            Stat::make('Collected this month', VendorUi::money($money['collected_month'])),
        ];
    }
}
