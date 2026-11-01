<?php

namespace App\Filament\Pages;

use App\Domain\Purchasing\SpendAnalytics;
use App\Filament\Support\Erp;
use App\Filament\Support\ExportsPage;
use App\Filament\Support\FinanceUi;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/** Where the money goes on buying, and how well it is controlled (procurement stage 5). */
class SpendAnalyticsPage extends Page
{
    use ExportsPage;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static string|UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?string $navigationLabel = 'Spend analytics';

    protected static ?string $title = 'Spend analytics';

    protected static ?string $slug = 'spend-analytics';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.spend-analytics';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $branch = '';

    public static function canAccess(): bool
    {
        return (Erp::canAnywhere('payables.view') || Erp::canAnywhere('purchasing.approve')) && config('erp.node.role') !== 'branch';
    }

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfYear()->toDateString();
        $this->to = $this->to ?: now()->toDateString();
    }

    public function branches(): array
    {
        return FinanceUi::branches();
    }

    public function data(): SpendAnalytics
    {
        return SpendAnalytics::for(Erp::user()->tenant_id, $this->from ?: now()->startOfYear()->toDateString(), $this->to ?: now()->toDateString(), $this->branch ?: null);
    }

    protected function exportFilters(): array
    {
        return ["From: {$this->from}", "To: {$this->to}", 'Branch: '.($this->branch ? ($this->branches()[$this->branch] ?? '') : 'All')];
    }
}
