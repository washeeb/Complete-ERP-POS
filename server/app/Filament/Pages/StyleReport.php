<?php

namespace App\Filament\Pages;

use App\Domain\Fashion\ConsignmentService;
use App\Domain\Fashion\FashionReports;
use App\Domain\Modules\ModuleService;
use App\Filament\Support\Erp;
use App\Filament\Support\ExportsPage;
use App\Models\Branch;
use App\Models\Style;
use App\Models\Supplier;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/** Sell-through by style and season, and what consignors are owed (with settlement). */
class StyleReport extends Page
{
    use ExportsPage;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static string|UnitEnum|null $navigationGroup = 'Fashion';

    protected static ?string $navigationLabel = 'Sell-through & consignment';

    protected static ?string $title = 'Sell-through & consignment';

    protected static ?string $slug = 'fashion-report';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.style-report';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $season = '';

    public string $statementNo = '';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('sales.view') && ModuleService::enabledAnywhere('fashion');
    }

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->to = $this->to ?: now()->toDateString();
    }

    public function seasons(): array
    {
        return Style::whereNotNull('season')->distinct()->orderBy('season')->pluck('season')->all();
    }

    public function styles(): array
    {
        return FashionReports::styles($this->from, $this->to, $this->season ?: null, Erp::branchIds('sales.view'));
    }

    public function canSettle(): bool
    {
        return Erp::canAnywhere('fashion.consignment') && Erp::canAnywhere('payables.manage');
    }

    public function owed()
    {
        return Erp::canAnywhere('fashion.consignment') ? ConsignmentService::owed(null, $this->to) : collect();
    }

    public function branchCode(string $id): string
    {
        return (string) Branch::whereKey($id)->value('code');
    }

    public function settle(string $consignorId, string $branchId): void
    {
        try {
            $bill = Erp::run(fn ($actor) => app(ConsignmentService::class)->settle($actor, Supplier::findOrFail($consignorId), Branch::findOrFail($branchId), $this->to,
                $this->statementNo ?: 'CONS-'.str_replace('-', '', $this->to)));
            Notification::make()->success()->title("Bill {$bill->number} raised")->body('It is in Payables → Supplier bills, to be approved and paid as usual.')->send();
        } catch (\Throwable) {
            // Erp::run already showed the reason
        }
    }
}
