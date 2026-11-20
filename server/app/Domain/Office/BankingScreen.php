<?php

namespace App\Domain\Office;

use App\Domain\Banking\BankingService;
use App\Domain\Banking\StatementCsv;
use App\Domain\Org\ArchiveService;
use App\Filament\Pages\BankReconciliation;
use App\Filament\Resources\BankAccounts\BankAccountResource;
use App\Filament\Resources\MoneyTransfers\MoneyTransferResource;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\MoneyTransfer;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bank & cash in the new back office (release 8 of the rewrite), as the current screens: the bank, mobile money and
 * cash accounts with their ledger balance and statement lines still to match (add, change, archive; Import statement
 * from a CSV with its columns named once; Move money between them), the reconciliation of one account (summary, the
 * statement and ledger lines to tick, suggestions, match, post a statement line, ignore, undo), and Money moved.
 * Everything changes through BankingService (BankingController).
 */
final class BankingScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return BankAccountResource::canViewAny();
    }

    public static function canManage(): bool
    {
        return Erp::canAnywhere('banking.manage');
    }

    public static function canReconcile(): bool
    {
        return BankReconciliation::canAccess();
    }

    public static function canTransfers(): bool
    {
        return MoneyTransferResource::canViewAny();
    }

    private function currency(): string
    {
        return $this->user->tenant?->currency_code ?? 'GHS';
    }

    /** @param  array<string, mixed>  $p  q, show (in_use | archived | all) */
    public function accounts(array $p): array
    {
        $q = BankAccount::with('account');
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn ($w) => $w->where('name', 'ilike', "%{$search}%")->orWhere('provider', 'ilike', "%{$search}%")->orWhere('number', 'ilike', "%{$search}%"));
        }
        match ($p['show'] ?? 'in_use') {
            'archived' => $q->whereNotNull('archived_at'),
            'all' => null,
            default => $q->whereNull('archived_at'),
        };
        $rows = $q->orderBy('name')->get();
        $balances = DB::table('journal_lines')->whereIn('account_id', $rows->pluck('account_id'))->groupBy('account_id')->selectRaw('account_id, SUM(amount_minor) AS s')->pluck('s', 'account_id');
        $open = DB::table('bank_statement_lines')->whereIn('bank_account_id', $rows->pluck('id'))->where('status', 'open')->groupBy('bank_account_id')
            ->selectRaw('bank_account_id, COUNT(*) AS n')->pluck('n', 'bank_account_id');
        $archive = app(ArchiveService::class);
        $manage = self::canManage();
        $currency = $this->currency();

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency), 'can_manage' => $manage, 'can_reconcile' => self::canReconcile(),
            'rows' => $rows->map(fn (BankAccount $b) => [
                'id' => $b->id, 'name' => $b->name, 'kind' => $b->kind, 'kind_label' => BankAccount::KINDS[$b->kind] ?? $b->kind, 'provider' => $b->provider, 'number' => $b->number,
                'account_id' => $b->account_id, 'ledger' => $b->account?->label(), 'balance' => (int) ($balances[$b->account_id] ?? 0), 'to_match' => (int) ($open[$b->id] ?? 0),
                'archived' => $b->archived_at !== null, 'can_archive' => ! $b->archived_at && $archive->allowed($this->user, $b), 'can_restore' => $b->archived_at && $archive->allowed($this->user, $b),
                'blockers' => ! $b->archived_at && $manage ? $archive->blockers($b) : [], 'mapping' => $b->csv_mapping ?? null,
            ])->all(),
        ];
    }

    /** What the account, import and Move money forms offer. */
    public function forms(): array
    {
        return [
            'kinds' => PurchaseOrdersScreen::choices(BankAccount::KINDS), 'ledger_accounts' => PurchaseOrdersScreen::choices(FinanceUi::accounts(withControl: false, types: ['asset'])),
            'money_accounts' => PurchaseOrdersScreen::choices(FinanceUi::moneyAccounts()), 'branches' => PurchaseOrdersScreen::choices(FinanceUi::branches()),
            'hq' => (string) FinanceUi::hq(), 'date_formats' => PurchaseOrdersScreen::choices(StatementCsv::DATE_FORMATS),
            'post_accounts' => PurchaseOrdersScreen::choices(FinanceUi::accounts(withControl: false)), 'can_manage' => self::canManage(),
        ];
    }

    /** One account's reconciliation as the current page: the summary at a date, the lines to tick, suggestions and recent matches. */
    public function reconcile(BankAccount $bank, ?string $asAt): array
    {
        $asAt = is_string($asAt) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asAt) ? $asAt : now()->toDateString();
        $svc = app(BankingService::class);
        $suggested = $svc->suggestions($bank);
        $currency = $this->currency();

        return [
            'bank' => ['id' => $bank->id, 'name' => $bank->name, 'ledger' => $bank->account?->label()], 'as_at' => $asAt,
            'currency' => $currency, 'exponent' => Money::exponent($currency), 'summary' => $svc->reconciliation($bank, $asAt), 'suggestions' => count($suggested),
            'statement' => BankStatementLine::where('bank_account_id', $bank->id)->where('status', 'open')->orderBy('line_date')->orderBy('id')->get()
                ->map(fn (BankStatementLine $l) => ['id' => $l->id, 'date' => $l->line_date->format('j M Y'), 'description' => $l->description, 'reference' => $l->reference,
                    'amount' => (int) $l->amount_minor, 'suggested' => isset($suggested[$l->id])])->all(),
            'ledger' => array_map(fn ($l) => ['id' => $l->id, 'date' => Carbon::parse($l->entry_date)->format('j M Y'), 'number' => $l->number, 'memo' => $l->line_memo ?: $l->memo,
                'amount' => (int) $l->amount_minor, 'suggested' => in_array($l->id, $suggested, true)], $svc->unmatchedLedger($bank)),
            'matches' => DB::table('bank_matches')->where('bank_account_id', $bank->id)->orderByDesc('created_at')->limit(10)->get()
                ->map(fn ($m) => ['id' => $m->id, 'at' => Carbon::parse($m->created_at)->format('j M Y H:i'), 'by' => $m->matched_by_name, 'amount' => (int) $m->amount_minor])->all(),
        ];
    }

    /** @param  array<string, mixed>  $p  q, sort (moved_on | amount_minor), dir, page, per */
    public function transfers(array $p): array
    {
        $q = MoneyTransferResource::getEloquentQuery()->with('fromAccount', 'toAccount');
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn ($w) => $w->where('number', 'ilike', "%{$search}%")->orWhere('reference', 'ilike', "%{$search}%"));
        }
        $sort = in_array($p['sort'] ?? null, ['moved_on', 'amount_minor', 'number'], true) ? $p['sort'] : 'moved_on';
        $dir = ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $currency = $this->currency();

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency), 'total' => (clone $q)->toBase()->getCountForPagination(), 'page' => $page, 'per' => $per,
            'sort' => $sort, 'dir' => $dir, 'can_move' => self::canManage(),
            'rows' => $q->orderBy($sort, $dir)->orderBy('id', $dir)->forPage($page, $per)->get()->map(fn (MoneyTransfer $t) => ['id' => $t->id, 'number' => $t->number,
                'date' => $t->moved_on?->toDateString(), 'from' => $t->fromAccount?->name, 'to' => $t->toAccount?->name, 'amount' => (int) $t->amount_minor,
                'reference' => $t->reference, 'note' => $t->note, 'by' => $t->moved_by_name])->all(),
        ];
    }
}
