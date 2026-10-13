<?php

namespace App\Filament\Pages;

use App\Domain\Finance\FinancialReports as Reports;
use App\Domain\Finance\GeneralLedger;
use App\Domain\Finance\GlCheck;
use App\Domain\Finance\HeadOfficeShare;
use App\Filament\Support\Erp;
use App\Filament\Support\ExportsPage;
use App\Filament\Support\FinanceUi;
use App\Models\Account;
use App\Models\Industry;
use App\Support\IndustryContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/** Trial balance, profit and loss (also by industry), balance sheet, account ledger and the sub-ledger tie-out. */
class FinancialReports extends Page
{
    use ExportsPage;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Reports';

    protected static ?string $title = 'Financial reports';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'financial-reports';

    protected string $view = 'filament.pages.financial-reports';

    public const REPORTS = ['tb' => 'Trial balance', 'pl' => 'Profit and loss', 'industries' => 'Profit by industry', 'bs' => 'Balance sheet', 'ledger' => 'Account ledger', 'budget' => 'Budget vs actual', 'vat' => 'VAT return', 'check' => 'Ledger check'];

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

    /** Industry (profit centre) id, FinancialReports::COMPANY for head office and shared lines, or null for everything. */
    #[Url]
    public ?string $industry = null;

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('finance.view') && config('erp.node.role') !== 'branch';
    }

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->to = $this->to ?: now()->toDateString();
        $this->industry ??= IndustryContext::selected(); // follows the industry chosen at the top
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
        $industry = $this->industry ?: null;

        return match ($this->report) {
            'pl' => $r->profitAndLoss($tenant, $this->from, $this->to, $branch, $industry),
            'industries' => $r->profitByIndustry($tenant, $this->from, $this->to),
            'bs' => $r->balanceSheet($tenant, $this->to, $branch, $industry),
            'ledger' => $this->account && ($a = Account::find($this->account)) ? $r->ledger($tenant, $a, $this->from, $this->to, $branch, $industry) + ['account' => $a] : [],
            'check' => app(GlCheck::class)->run($tenant),
            'vat' => $r->vatReturn($tenant, $this->from, $this->to),
            'budget' => $this->budget($r, $tenant, $branch, $industry),
            default => $r->trialBalance($tenant, $this->from, $this->to, $branch, $industry),
        };
    }

    /** Budget vs actual for the months of the financial year that the From–To dates fall in. */
    private function budget(Reports $r, string $tenant, ?string $branch, ?string $industry): array
    {
        $gl = app(GeneralLedger::class);
        $a = $gl->period($tenant, $this->from);
        $b = $gl->period($tenant, $this->to);
        $to = $b->year === $a->year ? $b->period : 12;

        return ['rows' => $r->budgetVsActual($tenant, $a->year, $a->period, $to, $branch, $industry), 'label' => $a->starts_on->format('M Y').' – '.$gl->period($tenant, $a->starts_on->copy()->addMonths($to - $a->period)->toDateString())->starts_on->format('M Y')];
    }

    public function accounts(): array
    {
        return FinanceUi::accounts();
    }

    public function branches(): array
    {
        return FinanceUi::branches();
    }

    /** @return array<string, string> */
    public function industries(): array
    {
        $list = Industry::licensed()->orderBy('sort')->orderBy('name')->pluck('name', 'id')->all();

        return $list ? $list + [Reports::COMPANY => 'Company (head office and shared)'] : [];
    }

    protected function getHeaderActions(): array
    {
        $share = app(HeadOfficeShare::class);

        return [
            Action::make('shareHeadOffice')->label('Share head office costs')->icon(Heroicon::OutlinedArrowsPointingOut)
                ->visible(fn () => $this->report === 'industries' && $share->enabled(Erp::user()->tenant_id) && Erp::canAnywhere('finance.journal'))
                ->requiresConfirmation()
                ->modalHeading(fn () => 'Share head office costs for '.$share->proposal(Erp::user()->tenant_id, $this->to)['month'])
                ->modalDescription(fn () => $this->shareSummary($share->proposal(Erp::user()->tenant_id, $this->to)))
                ->modalSubmitActionLabel('Post the journal')
                ->action(fn () => Erp::run(fn ($actor) => $share->post($actor, $this->to), 'Head office costs shared')),
            $this->exportGroup(),
        ];
    }

    /** What the sharing journal will post, in words, for the confirmation. */
    private function shareSummary(array $p): string
    {
        if ($p['done']) {
            return "Already shared in {$p['done']->number}. Reverse that journal (Finance → Journals) to share the month again.";
        }
        if (! $p['basis'] || ! $p['lines']) {
            return $p['basis'] ? 'There are no head office costs this month.' : 'No industry has sales this month, so there is nothing to share by.';
        }
        $sales = array_sum($p['basis']);
        $parts = [];
        foreach ($p['basis'] as $id => $b) {
            $parts[] = ($p['names'][$id] ?? '?').' '.round($b * 100 / $sales, 1).'%';
        }

        return 'Head office costs of '.FinanceUi::money($p['total'])." in {$p['month']} are shared by this month's sales: ".implode(', ', $parts)
            .'. One journal moves each cost account\'s amount from Company to the industries. It can be reversed.';
    }
}
