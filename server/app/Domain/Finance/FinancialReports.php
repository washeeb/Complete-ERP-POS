<?php

namespace App\Domain\Finance;

use App\Models\Account;
use App\Models\FiscalPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Trial balance, profit and loss, balance sheet and account ledger, straight from the journal lines.
 * Income and expense accounts start each financial year at zero; earlier years' profit is shown in retained
 * earnings (no closing entries are needed). A branch filter shows that branch's lines only (cost centre).
 */
final class FinancialReports
{
    public function __construct(private readonly GeneralLedger $gl) {}

    public function yearStart(string $tenantId, string $date): string
    {
        $p = $this->gl->period($tenantId, $date);

        return FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('year', $p->year)->where('period', 1)->value('starts_on')->toDateString();
    }

    /**
     * @return array{rows: list<array{account: Account, opening: int, debit: int, credit: int, closing: int}>, totals: array{opening: int, debit: int, credit: int, closing: int}}
     */
    public function trialBalance(string $tenantId, string $from, string $to, ?string $branchId = null): array
    {
        $ys = $this->yearStart($tenantId, $from);
        $sums = collect(DB::select(<<<'SQL'
            SELECT l.account_id,
                SUM(CASE WHEN l.entry_date < ? THEN l.amount_minor ELSE 0 END) AS before_year,
                SUM(CASE WHEN l.entry_date >= ? AND l.entry_date < ? THEN l.amount_minor ELSE 0 END) AS before_from,
                SUM(CASE WHEN l.entry_date BETWEEN ? AND ? AND l.amount_minor > 0 THEN l.amount_minor ELSE 0 END) AS debit,
                SUM(CASE WHEN l.entry_date BETWEEN ? AND ? AND l.amount_minor < 0 THEN -l.amount_minor ELSE 0 END) AS credit
            FROM journal_lines l
            WHERE l.tenant_id = ? AND l.entry_date <= ? AND (?::uuid IS NULL OR l.branch_id = ?::uuid)
            GROUP BY l.account_id
        SQL, [$ys, $ys, $from, $from, $to, $from, $to, $tenantId, $to, $branchId, $branchId]))->keyBy('account_id');

        $accounts = Account::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_header', false)->orderBy('code')->get();
        $priorProfit = 0;
        foreach ($accounts as $a) {
            if (! $a->isBalanceSheet()) {
                $priorProfit += (int) ($sums[$a->id]->before_year ?? 0);
            }
        }
        $rows = [];
        $totals = ['opening' => 0, 'debit' => 0, 'credit' => 0, 'closing' => 0];
        foreach ($accounts as $a) {
            $s = $sums[$a->id] ?? null;
            $opening = (int) ($s->before_from ?? 0) + ($a->isBalanceSheet() ? (int) ($s->before_year ?? 0) : 0)
                + ($a->system_key === 'retained_earnings' ? $priorProfit : 0);
            $debit = (int) ($s->debit ?? 0);
            $credit = (int) ($s->credit ?? 0);
            if ($opening === 0 && $debit === 0 && $credit === 0) {
                continue;
            }
            $row = ['account' => $a, 'opening' => $opening, 'debit' => $debit, 'credit' => $credit, 'closing' => $opening + $debit - $credit];
            $rows[] = $row;
            foreach ($totals as $k => $_) {
                $totals[$k] += $row[$k];
            }
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Profit and loss for a date range, by top-level heading; "cost of sales" is the heading holding the cost of goods sold account.
     *
     * @return array{sections: list<array{name: string, kind: string, rows: list<array{account: Account, amount: int}>, total: int}>, income: int, cost_of_sales: int, gross_profit: int, expenses: int, net_profit: int}
     */
    public function profitAndLoss(string $tenantId, string $from, string $to, ?string $branchId = null): array
    {
        $sums = $this->sums($tenantId, $from, $to, $branchId);
        $accounts = Account::withoutGlobalScopes()->where('tenant_id', $tenantId)->get()->keyBy('id');
        $cogsTop = $this->top($accounts, $accounts->firstWhere('system_key', 'cogs'))?->id;
        $sections = [];
        foreach ($accounts->sortBy('code') as $a) {
            if ($a->is_header || $a->isBalanceSheet() || ! isset($sums[$a->id])) {
                continue;
            }
            $top = $this->top($accounts, $a);
            $amount = $a->type === 'income' ? -$sums[$a->id] : $sums[$a->id];
            $kind = $a->type === 'income' ? 'income' : ($top?->id === $cogsTop ? 'cost_of_sales' : 'expense');
            $sections[$top->id ?? $a->id] ??= ['name' => $top->name ?? $a->name, 'kind' => $kind, 'code' => $top->code ?? $a->code, 'rows' => [], 'total' => 0];
            $sections[$top->id ?? $a->id]['rows'][] = ['account' => $a, 'amount' => $amount];
            $sections[$top->id ?? $a->id]['total'] += $amount;
        }
        $sections = array_values(collect($sections)->sortBy('code')->all());
        $sum = fn (string $kind) => array_sum(array_map(fn ($s) => $s['kind'] === $kind ? $s['total'] : 0, $sections));
        $income = $sum('income');
        $cos = $sum('cost_of_sales');
        $exp = $sum('expense');

        return ['sections' => $sections, 'income' => $income, 'cost_of_sales' => $cos, 'gross_profit' => $income - $cos, 'expenses' => $exp,
            'net_profit' => $income - $cos - $exp];
    }

    /**
     * Balance sheet at a date. Equity includes earlier years' profit (in retained earnings) and this year's profit to date.
     *
     * @return array{assets: list<array>, liabilities: list<array>, equity: list<array>, total_assets: int, total_liabilities: int, total_equity: int, profit_this_year: int, balanced: bool}
     */
    public function balanceSheet(string $tenantId, string $asAt, ?string $branchId = null): array
    {
        $tb = $this->trialBalance($tenantId, $this->yearStart($tenantId, $asAt), $asAt, $branchId);
        $out = ['asset' => [], 'liability' => [], 'equity' => []];
        $profit = 0;
        foreach ($tb['rows'] as $r) {
            $a = $r['account'];
            if (! $a->isBalanceSheet()) {
                $profit -= $r['closing']; // income is a credit (−), so profit is minus the P&L closing balances

                continue;
            }
            $amount = $a->type === 'asset' ? $r['closing'] : -$r['closing'];
            if ($amount !== 0) {
                $out[$a->type][] = ['account' => $a, 'amount' => $amount];
            }
        }
        $assets = array_sum(array_column($out['asset'], 'amount'));
        $liabilities = array_sum(array_column($out['liability'], 'amount'));
        $equity = array_sum(array_column($out['equity'], 'amount')) + $profit;

        return ['assets' => $out['asset'], 'liabilities' => $out['liability'], 'equity' => $out['equity'], 'profit_this_year' => $profit,
            'total_assets' => $assets, 'total_liabilities' => $liabilities, 'total_equity' => $equity, 'balanced' => $assets === $liabilities + $equity];
    }

    /**
     * Lines on one account with a running balance.
     *
     * @return array{opening: int, lines: list<object>, closing: int}
     */
    public function ledger(string $tenantId, Account $account, string $from, string $to, ?string $branchId = null): array
    {
        $since = $account->isBalanceSheet() ? null : $this->yearStart($tenantId, $from);
        $opening = (int) DB::table('journal_lines')->where('account_id', $account->id)->where('entry_date', '<', $from)
            ->when($since, fn ($q) => $q->where('entry_date', '>=', $since))->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->sum('amount_minor');
        $lines = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->leftJoin('branches as b', 'b.id', '=', 'l.branch_id')
            ->where('l.account_id', $account->id)->whereBetween('l.entry_date', [$from, $to])->when($branchId, fn ($q) => $q->where('l.branch_id', $branchId))
            ->orderBy('l.entry_date')->orderBy('e.number')->orderBy('l.line_no')
            ->get(['l.entry_date', 'e.number', 'e.memo', 'e.id as entry_id', 'l.memo as line_memo', 'l.amount_minor', 'l.party_type', 'l.party_id', 'b.code as branch'])->all();
        $running = $opening;
        foreach ($lines as $l) {
            $running += (int) $l->amount_minor;
            $l->running_minor = $running;
        }

        return ['opening' => $opening, 'lines' => $lines, 'closing' => $running];
    }

    /** @return array<string, int> */
    private function sums(string $tenantId, string $from, string $to, ?string $branchId): array
    {
        return DB::table('journal_lines')->where('tenant_id', $tenantId)->whereBetween('entry_date', [$from, $to])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->groupBy('account_id')
            ->selectRaw('account_id, SUM(amount_minor) AS s')->pluck('s', 'account_id')->map(fn ($v) => (int) $v)->all();
    }

    private function top($accounts, ?Account $a): ?Account
    {
        for ($i = 0; $a && $a->parent_id && $i < 10; $i++) {
            $a = $accounts[$a->parent_id] ?? null;
        }

        return $a;
    }
}
