<?php

namespace App\Filament\Pages;

use App\Domain\Finance\FinancialReports as Reports;
use App\Domain\Finance\GeneralLedger;
use App\Domain\Finance\GlCheck;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Account;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/** Trial balance, profit and loss, balance sheet, account ledger and the sub-ledger tie-out. */
class FinancialReports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Reports';

    protected static ?string $title = 'Financial reports';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'financial-reports';

    protected string $view = 'filament.pages.financial-reports';

    public const REPORTS = ['tb' => 'Trial balance', 'pl' => 'Profit and loss', 'bs' => 'Balance sheet', 'ledger' => 'Account ledger', 'budget' => 'Budget vs actual', 'vat' => 'VAT return', 'check' => 'Ledger check'];

    #[Url]
    public string $report = 'tb';

    #[Url]
    public ?string $account = null;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public ?string $branch = null;

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('finance.view') && config('erp.node.role') !== 'branch';
    }

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->to = $this->to ?: now()->toDateString();
    }

    public function money(?int $v): string
    {
        return $v ? FinanceUi::money($v) : '';
    }

    public function data(): array
    {
        $tenant = Erp::user()->tenant_id;
        $r = app(Reports::class);
        $branch = $this->branch ?: null;

        return match ($this->report) {
            'pl' => $r->profitAndLoss($tenant, $this->from, $this->to, $branch),
            'bs' => $r->balanceSheet($tenant, $this->to, $branch),
            'ledger' => $this->account && ($a = Account::find($this->account)) ? $r->ledger($tenant, $a, $this->from, $this->to, $branch) + ['account' => $a] : [],
            'check' => app(GlCheck::class)->run($tenant),
            'vat' => $r->vatReturn($tenant, $this->from, $this->to),
            'budget' => $this->budget($r, $tenant, $branch),
            default => $r->trialBalance($tenant, $this->from, $this->to, $branch),
        };
    }

    /** Budget vs actual for the months of the financial year that the From–To dates fall in. */
    private function budget(Reports $r, string $tenant, ?string $branch): array
    {
        $gl = app(GeneralLedger::class);
        $a = $gl->period($tenant, $this->from);
        $b = $gl->period($tenant, $this->to);
        $to = $b->year === $a->year ? $b->period : 12;

        return ['rows' => $r->budgetVsActual($tenant, $a->year, $a->period, $to, $branch), 'label' => $a->starts_on->format('M Y').' – '.$gl->period($tenant, $a->starts_on->copy()->addMonths($to - $a->period)->toDateString())->starts_on->format('M Y')];
    }

    public function accounts(): array
    {
        return FinanceUi::accounts();
    }

    public function branches(): array
    {
        return FinanceUi::branches();
    }
}
