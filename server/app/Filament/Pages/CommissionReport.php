<?php

namespace App\Filament\Pages;

use App\Domain\Modules\ModuleService;
use App\Domain\Services\CommissionService;
use App\Filament\Support\Erp;
use App\Models\Commission;
use App\Models\Tenant;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use UnitEnum;

/** Commission earned by each staff member in a period (net of returns), and the lines behind it. Paid with payroll. */
class CommissionReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Services';

    protected static ?string $navigationLabel = 'Commissions';

    protected static ?string $title = 'Commissions';

    protected static ?string $slug = 'commissions';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.commission-report';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $employeeId = '';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('services.commissions') && ModuleService::enabledAnywhere('services');
    }

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->to = $this->to ?: now()->toDateString();
    }

    public function tz(): string
    {
        return Tenant::find(Erp::user()->tenant_id)?->timezone ?: 'UTC';
    }

    public function summary()
    {
        return CommissionService::summary($this->from, $this->to, $this->tz(), Erp::branchIds('services.commissions'));
    }

    public function lines()
    {
        if (! $this->employeeId) {
            return collect();
        }
        $ids = Erp::branchIds('services.commissions');

        return Commission::where('employee_id', $this->employeeId)->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))
            ->where('occurred_at', '>=', Carbon::parse($this->from, $this->tz())->startOfDay()->utc())->where('occurred_at', '<', Carbon::parse($this->to, $this->tz())->addDay()->startOfDay()->utc())
            ->join('sale_lines as l', 'l.id', '=', 'commissions.sale_line_id')->join('sales as s', 's.id', '=', 'commissions.sale_id')
            ->orderBy('commissions.occurred_at')->limit(1000)
            ->get(['commissions.*', 'l.name', 'l.qty', 's.receipt_no']);
    }
}
