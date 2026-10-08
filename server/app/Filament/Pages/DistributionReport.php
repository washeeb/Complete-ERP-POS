<?php

namespace App\Filament\Pages;

use App\Domain\Distribution\DistributionReports;
use App\Domain\Modules\ModuleService;
use App\Filament\Support\Erp;
use App\Filament\Support\ExportsPage;
use App\Models\Tenant;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/** Rep performance against target, customers missed on today's routes, and the empties customers hold. */
class DistributionReport extends Page
{
    use ExportsPage;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Distribution';

    protected static ?string $navigationLabel = 'Rep performance';

    protected static ?string $title = 'Rep performance';

    protected static ?string $slug = 'rep-performance';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.distribution-report';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $date = '';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('sales.view') && ModuleService::enabledAnywhere('distribution');
    }

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->to = $this->to ?: now()->toDateString();
        $this->date = $this->date ?: now()->toDateString();
    }

    public function tz(): string
    {
        return Tenant::find(Erp::user()->tenant_id)?->timezone ?: 'UTC';
    }

    public function reps(): array
    {
        return DistributionReports::reps($this->from, $this->to, $this->tz(), Erp::branchIds('sales.view'));
    }

    public function missed(): array
    {
        return DistributionReports::missedVisits($this->date, $this->tz(), Erp::branchIds('sales.view'));
    }

    public function empties(): array
    {
        return DistributionReports::empties(Erp::branchIds('sales.view'));
    }
}
