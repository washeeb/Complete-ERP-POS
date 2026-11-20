<?php

namespace App\Domain\Office;

use App\Domain\Budgets\BudgetService;
use App\Domain\Finance\AccountService;
use App\Domain\Finance\GeneralLedger;
use App\Domain\Finance\PeriodService;
use App\Domain\Org\ArchiveService;
use App\Filament\Pages\Budgets;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\FiscalPeriods\FiscalPeriodResource;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Support\Codes;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The books' set-up in the new back office (release 8 of the rewrite), as the current screens: the chart of accounts
 * (headings, types, automatic-posting purposes, balances; add, change, archive and restore through AccountService and
 * ArchiveService), month-end (close a month once everything is posted and tied, reopen with a reason, set up a
 * financial year, through PeriodService) and budgets (twelve months per income and cost account and branch, Spread a
 * yearly figure, through BudgetService). BooksController.
 */
final class BooksScreen
{
    public function __construct(private readonly User $user) {}

    public static function canAccounts(): bool
    {
        return AccountResource::canViewAny();
    }

    public static function canManageAccounts(): bool
    {
        return Erp::canAnywhere('finance.accounts.manage');
    }

    public static function canPeriods(): bool
    {
        return FiscalPeriodResource::canViewAny();
    }

    public static function canBudgets(): bool
    {
        return Budgets::canAccess();
    }

    private function currency(): string
    {
        return $this->user->tenant?->currency_code ?? 'GHS';
    }

    // ---- Chart of accounts ----------------------------------------------------------------------------------------

    /** @param  array<string, mixed>  $p  q, type, show (in_use | archived | all) */
    public function accounts(array $p): array
    {
        $q = Account::query();
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn ($w) => $w->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"));
        }
        if (isset(Account::TYPES[$p['type'] ?? ''])) {
            $q->where('type', $p['type']);
        }
        match ($p['show'] ?? 'in_use') {
            'archived' => $q->whereNotNull('archived_at'),
            'all' => null,
            default => $q->whereNull('archived_at'),
        };
        $balances = DB::table('journal_lines')->groupBy('account_id')->selectRaw('account_id, SUM(amount_minor) AS s')->pluck('s', 'account_id');
        $purposes = AccountService::systemKeys($this->user->tenant_id);
        $archive = app(ArchiveService::class);
        $manage = self::canManageAccounts();
        $currency = $this->currency();

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency),
            'rows' => $q->orderBy('code')->get()->map(fn (Account $a) => [
                'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'type_label' => Account::TYPES[$a->type] ?? $a->type,
                'header' => (bool) $a->is_header, 'parent_id' => $a->parent_id, 'control' => $a->control ? Account::CONTROLS[$a->control] ?? $a->control : null,
                'purpose' => $a->system_key ? ($purposes[$a->system_key][0] ?? $a->system_key) : null, 'system_key' => $a->system_key,
                'description' => $a->description, 'balance' => $a->is_header ? null : (int) ($balances[$a->id] ?? 0),
                'archived' => $a->archived_at !== null, 'archive_note' => $a->archived_at ? trim('Archived '.$a->archived_at->format('j M Y').($a->archive_reason ? ": {$a->archive_reason}" : '')) : null,
                'can_edit' => $manage, 'can_archive' => ! $a->archived_at && $archive->allowed($this->user, $a), 'can_restore' => $a->archived_at && $archive->allowed($this->user, $a),
                'blockers' => ! $a->archived_at && $manage ? $archive->blockers($a) : [],
                'ledger' => $a->is_header ? null : "/office/financial-reports?report=ledger&account={$a->id}",
            ])->all(),
        ];
    }

    /** What the account form offers: types, headings, purposes, and the next free code by type and by heading. */
    public function accountForm(): array
    {
        $headers = Account::where('is_header', true)->orderBy('code')->get();

        return [
            'types' => PurchaseOrdersScreen::choices(Account::TYPES),
            'headers' => $headers->map(fn ($a) => ['id' => $a->id, 'label' => $a->label(), 'type' => $a->type])->all(),
            'purposes' => collect(AccountService::systemKeys($this->user->tenant_id))->map(fn ($k, $id) => ['id' => $id, 'label' => $k[0], 'type' => $k[1]])->values()->all(),
            'next_by_type' => collect(Account::TYPES)->map(fn ($_, $t) => Codes::after(Account::where('type', $t)->where('is_header', false)->pluck('code')))->all(),
            'next_by_header' => $headers->mapWithKeys(fn ($h) => [$h->id => Codes::after(Account::where('parent_id', $h->id)->pluck('code'))])->all(),
            'can_manage' => self::canManageAccounts(),
        ];
    }

    // ---- Month-end ------------------------------------------------------------------------------------------------

    public function periods(?string $year): array
    {
        $years = FiscalPeriod::distinct()->orderBy('year')->pluck('year')->all();
        $current = app(GeneralLedger::class)->period($this->user->tenant_id, now()->toDateString())->year;
        $y = is_numeric($year) && in_array((int) $year, $years, true) ? (int) $year : $current;
        $svc = app(PeriodService::class);
        $close = Erp::canAnywhere('finance.close');
        $reopen = Erp::canAnywhere('finance.reopen');

        return [
            'year' => $y, 'years' => array_map(fn ($v) => ['id' => (string) $v, 'label' => "FY{$v}"], $years), 'can_setup' => $close,
            'rows' => FiscalPeriod::where('year', $y)->orderBy('period')->get()->map(fn (FiscalPeriod $p) => [
                'id' => $p->id, 'month' => $p->starts_on->format('F Y'), 'period' => $p->period, 'status' => $p->status, 'closed_by' => $p->closed_by_name,
                'closed_at' => $p->closed_at?->format('j M Y H:i'),
                'can_close' => $p->status === 'open' && $close, 'blockers' => $p->status === 'open' && $close ? $svc->blockers($p) : [],
                'can_reopen' => $p->status === 'closed' && $reopen,
            ])->all(),
        ];
    }

    // ---- Budgets --------------------------------------------------------------------------------------------------

    public function budget(?string $year, ?string $branch): array
    {
        $tenant = $this->user->tenant_id;
        $gl = app(GeneralLedger::class);
        $y = is_numeric($year) && (int) $year >= 2000 && (int) $year <= 2100 ? (int) $year : $gl->period($tenant, now()->toDateString())->year;
        $branches = FinanceUi::branches();
        $b = isset($branches[$branch ?? '']) ? (string) $branch : (string) FinanceUi::hq();
        $gl->period($tenant, sprintf('%04d-06-15', $y)); // make sure the year's months exist
        $saved = app(BudgetService::class)->load($tenant, $y, $b);

        return [
            'year' => $y, 'branch' => $b, 'branches' => PurchaseOrdersScreen::choices($branches), 'can_save' => Erp::canAnywhere('budgets.manage'),
            'months' => FiscalPeriod::where('year', $y)->orderBy('period')->get()->map(fn ($p) => $p->starts_on->format('M'))->all(),
            'rows' => Account::whereIn('type', ['income', 'expense'])->where('is_header', false)->where('is_active', true)->orderBy('code')->get()
                ->map(fn (Account $a) => ['id' => $a->id, 'label' => $a->label(), 'type' => $a->type,
                    'amounts' => array_map(fn ($v) => $v ? number_format($v / 100, 2, '.', '') : '', $saved[$a->id] ?? array_fill(0, 12, 0))])->all(),
        ];
    }

    /** A yearly figure shared over the twelve months, as the current page's Spread (the remainder in the last months). */
    public static function spread(string $annual): array
    {
        return array_map(fn ($v) => $v ? number_format($v / 100, 2, '.', '') : '', BudgetService::spread(FinanceUi::toMinor($annual === '' ? 0 : $annual)));
    }
}
