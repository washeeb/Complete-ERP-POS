<?php

namespace App\Domain\Office;

use App\Domain\Projects\Projects;
use App\Filament\Resources\ExpenseClaims\ExpenseClaimResource;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Account;
use App\Models\Branch;
use App\Models\ExpenseClaim;
use App\Models\Project;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * Expense claims and petty cash in the new back office (release 6 of the rewrite): the list with the current screen's
 * search, status filter and columns; one claim with Approve, Reject and Pay; and New claim with the receipt photo. Same
 * query (ExpenseClaimResource: your own claims, and every claim where you approve or pay), same rules: ExpenseService
 * (ExpenseClaimsController).
 */
final class ExpenseClaimsScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const SORTS = ['number', 'spent_on', 'amount_minor'];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return ExpenseClaimResource::canViewAny();
    }

    public static function canCreate(): bool
    {
        return ExpenseClaimResource::canCreate();
    }

    public function filtered(array $p): Builder
    {
        $q = ExpenseClaimResource::getEloquentQuery();
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn (Builder $w) => $w->where('expense_claims.number', 'ilike', "%{$search}%")->orWhere('expense_claims.description', 'ilike', "%{$search}%")
                ->orWhere('expense_claims.claimant_name', 'ilike', "%{$search}%")->orWhere('expense_claims.payee', 'ilike', "%{$search}%"));
        }
        if (isset(ExpenseClaim::STATUSES[$p['status'] ?? ''])) {
            $q->where('expense_claims.status', $p['status']);
        }
        if (($p['mine'] ?? '') === '1') {
            $q->where('expense_claims.claimant_id', $this->user->id);
        }

        return $q;
    }

    /** @return array{0: string, 1: string} */
    public function order(array $p): array
    {
        return [in_array($p['sort'] ?? null, self::SORTS, true) ? $p['sort'] : 'number', ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc'];
    }

    public function sorted(array $p): Builder
    {
        [$sort, $dir] = $this->order($p);

        return $this->filtered($p)->orderBy("expense_claims.{$sort}", $dir)->orderBy('expense_claims.id', $dir);
    }

    public function rowOf(ExpenseClaim $c): array
    {
        return [
            'id' => $c->id, 'number' => $c->number, 'spent_on' => $c->spent_on?->toDateString(), 'by' => $c->claimant_name, 'branch' => $c->branch?->code,
            'description' => $c->description, 'for' => $c->account?->name, 'amount' => (int) $c->amount_minor, 'status' => $c->status,
            'mine' => $c->claimant_id === $this->user->id,
        ];
    }

    /** @param  array<string, mixed>  $p  q, status, mine, sort, dir, page, per */
    public function list(array $p): array
    {
        $me = $this->user->id;
        $t = (clone $this->filtered($p))->toBase()->reorder()->selectRaw('COUNT(*) AS n, COALESCE(SUM(amount_minor), 0) AS amount, '
            ."COUNT(*) FILTER (WHERE status = 'submitted' AND claimant_id <> ?) AS waiting, COALESCE(SUM(amount_minor) FILTER (WHERE status = 'approved'), 0) AS to_pay", [$me])->first();
        [$sort, $dir] = $this->order($p);
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $currency = $this->currency();

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency),
            'total' => (int) $t->n, 'amount' => (int) $t->amount, 'waiting' => Erp::canAnywhere('expenses.approve') ? (int) $t->waiting : null, 'to_pay' => (int) $t->to_pay,
            'page' => $page, 'per' => $per, 'sort' => $sort, 'dir' => $dir,
            'rows' => $this->sorted($p)->forPage($page, $per)->get()->map(fn (ExpenseClaim $c) => $this->rowOf($c))->all(),
        ];
    }

    public function options(): array
    {
        return ['statuses' => PurchaseOrdersScreen::choices(ExpenseClaim::STATUSES), 'per' => self::PER_PAGE, 'can_create' => self::canCreate()];
    }

    /** One claim: what the current page shows, and Approve / Reject / Pay (with where it may be paid from). */
    public function show(ExpenseClaim $c): array
    {
        $c->loadMissing(['account', 'branch']);
        $notMine = $c->claimant_id !== $this->user->id;
        $approver = Erp::can('expenses.approve', $c->branch);
        $currency = $this->currency();

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency),
            'id' => $c->id, 'number' => $c->number, 'status' => $c->status, 'status_label' => ExpenseClaim::STATUSES[$c->status] ?? $c->status,
            'by' => $c->claimant_name, 'branch' => $c->branch?->name, 'spent_on' => $c->spent_on?->toDateString(), 'for' => $c->account?->name,
            'amount' => (int) $c->amount_minor, 'payee' => $c->payee, 'description' => $c->description,
            'receipt' => $c->receipt_path ? route('expense.receipt', $c->id, false) : null,
            'decided_by' => $c->approved_by_name, 'rejection_reason' => $c->rejection_reason,
            'paid' => $c->paid_by_name ? ['by' => $c->paid_by_name, 'on' => $c->paid_on?->toDateString(), 'from' => Account::find($c->paid_from_account_id)?->name] : null,
            'actions' => [
                'approve' => $c->status === 'submitted' && $notMine && $approver,
                'reject' => in_array($c->status, ['submitted', 'approved'], true) && $notMine && $approver,
                'pay' => $c->status === 'approved' && Erp::can('expenses.pay', $c->branch) ? [
                    'accounts' => PurchaseOrdersScreen::choices(FinanceUi::moneyAccounts()), 'default' => (string) Account::where('system_key', 'petty_cash')->value('id'),
                ] : null,
            ],
        ];
    }

    /** New claim: the branches the person claims in, what it may be for, and projects (Projects add-on). */
    public function form(): array
    {
        $ids = Erp::branchIds('expenses.claim');

        return [
            'currency' => $this->currency(),
            'branches' => PurchaseOrdersScreen::choices(Branch::when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('code')->get()
                ->mapWithKeys(fn ($b) => [$b->id => "{$b->code} · {$b->name}"])->all()),
            'accounts' => PurchaseOrdersScreen::choices(FinanceUi::accounts(withControl: false, types: ['expense'])),
            'projects' => Projects::enabled() ? PurchaseOrdersScreen::choices(Project::whereIn('status', ['planned', 'active', 'on_hold'])->orderBy('number')->get()
                ->mapWithKeys(fn ($p) => [$p->id => "{$p->number} {$p->name}"])->all()) : null,
        ];
    }

    private function currency(): string
    {
        return $this->user->tenant?->currency_code ?? 'GHS';
    }
}
