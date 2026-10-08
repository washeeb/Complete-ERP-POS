<?php

namespace App\Filament\Pages;

use App\Domain\Budgets\BudgetService;
use App\Domain\Finance\GeneralLedger;
use App\Domain\Support\DomainException;
use App\Filament\Support\Erp;
use App\Filament\Support\ExportsPage;
use App\Filament\Support\FinanceUi;
use App\Models\Account;
use App\Models\Branch;
use App\Models\FiscalPeriod;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/** Budgets per branch × income/expense account × month (owner decision 2026-10-13). */
class Budgets extends Page
{
    use ExportsPage;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Budgets';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.budgets';

    #[Url]
    public int $year = 0;

    #[Url]
    public string $branch = '';

    /** @var array<string, list<string>> account id → 12 amounts as typed */
    public array $amounts = [];

    /** @var array<string, string> account id → annual amount to spread */
    public array $annual = [];

    public static function canAccess(): bool
    {
        return (Erp::canAnywhere('budgets.manage') || Erp::canAnywhere('finance.view')) && config('erp.node.role') !== 'branch';
    }

    public function mount(): void
    {
        $tenant = Erp::user()->tenant_id;
        $this->year = $this->year ?: app(GeneralLedger::class)->period($tenant, now()->toDateString())->year;
        $this->branch = $this->branch ?: (string) FinanceUi::hq();
        $this->loadAmounts();
    }

    public function updatedYear(): void
    {
        $this->loadAmounts();
    }

    public function updatedBranch(): void
    {
        $this->loadAmounts();
    }

    public function loadAmounts(): void
    {
        $saved = app(BudgetService::class)->load(Erp::user()->tenant_id, $this->year, $this->branch);
        $this->amounts = [];
        $this->annual = [];
        foreach ($this->accounts() as $a) {
            $this->amounts[$a->id] = array_map(fn ($v) => $v ? number_format($v / 100, 2, '.', '') : '', $saved[$a->id] ?? array_fill(0, 12, 0));
        }
    }

    public function accounts()
    {
        return Account::whereIn('type', ['income', 'expense'])->where('is_header', false)->where('is_active', true)->orderBy('code')->get();
    }

    public function months(): array
    {
        app(GeneralLedger::class)->period(Erp::user()->tenant_id, sprintf('%04d-06-15', $this->year)); // make sure the year's months exist

        return FiscalPeriod::where('year', $this->year)->orderBy('period')->get()->map(fn ($p) => $p->starts_on->format('M'))->all();
    }

    public function branches(): array
    {
        return FinanceUi::branches();
    }

    public function spread(string $accountId): void
    {
        $per = BudgetService::spread(FinanceUi::toMinor($this->annual[$accountId] ?? 0));
        $this->amounts[$accountId] = array_map(fn ($v) => $v ? number_format($v / 100, 2, '.', '') : '', $per);
    }

    public function save(): void
    {
        $rows = [];
        try {
            foreach ($this->amounts as $id => $months) {
                $rows[$id] = array_map(fn ($v) => FinanceUi::toMinor(trim((string) $v) === '' ? 0 : $v), array_values($months));
            }
            $n = app(BudgetService::class)->save(Erp::actor(), $this->year, Branch::findOrFail($this->branch), $rows);
        } catch (DomainException $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();

            return;
        }
        Notification::make()->success()->title('Budget saved')->send();
        $this->loadAmounts();
    }

    public function canSave(): bool
    {
        return Erp::canAnywhere('budgets.manage');
    }
}
