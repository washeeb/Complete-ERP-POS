<?php

namespace App\Domain\Purchasing;

use App\Domain\Finance\GeneralLedger;
use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Support\Money;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Budget check at requisition (procurement stage 4). For the service and non-stock lines of a request or order (stock
 * goes to the stock account, not a budget), per expense account at the branch: the budget for the financial year,
 * what has already been spent this year (the books), what is already committed on open orders not yet billed, and
 * this order. Setting `purchasing.budget_check`: off, warn (shown and recorded), or approval (an order over budget
 * also needs someone with "Approve orders over budget"). An account with no budget counts as over.
 */
final class BudgetCheck
{
    public const MODES = ['off' => 'Off', 'warn' => 'Warn and record', 'approval' => 'Over budget needs a second approval'];

    public function __construct(private readonly Settings $settings) {}

    public function mode(string $tenantId): string
    {
        return (string) $this->settings->get('purchasing.budget_check', $tenantId);
    }

    /**
     * @param  list<array{account_id: ?string, net_minor: int}>  $lines
     * @return list<array{account: Account, budget: int, spent: int, committed: int, this: int, left: int, over: bool}> empty when nothing to check
     */
    public function check(string $tenantId, string $branchId, array $lines, ?string $exceptPoId = null): array
    {
        $byAccount = [];
        foreach ($lines as $l) {
            if ($l['account_id'] ?? null) {
                $byAccount[$l['account_id']] = ($byAccount[$l['account_id']] ?? 0) + (int) $l['net_minor'];
            }
        }
        if ($byAccount === []) {
            return [];
        }
        $year = app(GeneralLedger::class)->period($tenantId, today()->toDateString())->year; // the financial year, made if not yet there
        $months = FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('year', $year);
        [$from, $to] = [(clone $months)->min('starts_on'), (clone $months)->max('ends_on')];
        $out = [];
        foreach (Account::withoutGlobalScopes()->whereIn('id', array_keys($byAccount))->where('type', 'expense')->orderBy('code')->get() as $a) {
            $budget = (int) DB::table('budget_amounts')->where('tenant_id', $tenantId)->where('year', $year)->where('branch_id', $branchId)->where('account_id', $a->id)->sum('amount_minor');
            $spent = (int) DB::table('journal_lines')->where('tenant_id', $tenantId)->where('account_id', $a->id)->where('branch_id', $branchId)
                ->whereBetween('entry_date', [$from, $to])->sum('amount_minor');
            $committed = $this->committed($tenantId, $branchId, $a->id, $exceptPoId);
            $this_ = $byAccount[$a->id];
            $left = $budget - $spent - $committed - $this_;
            $out[] = ['account' => $a, 'budget' => $budget, 'spent' => $spent, 'committed' => $committed, 'this' => $this_, 'left' => $left, 'over' => $left < 0];
        }

        return $out;
    }

    /** Open orders' service and non-stock lines on the account, less what has been billed on them. */
    public function committed(string $tenantId, string $branchId, string $accountId, ?string $exceptPoId = null): int
    {
        $lines = DB::table('purchase_order_lines as l')->join('purchase_orders as o', 'o.id', '=', 'l.purchase_order_id')
            ->where('o.tenant_id', $tenantId)->where('o.branch_id', $branchId)->where('l.account_id', $accountId)
            ->whereIn('o.status', ['pending_approval', 'approved', 'partially_received', 'received'])
            ->when($exceptPoId, fn ($q) => $q->where('o.id', '!=', $exceptPoId))->get(['l.id', 'l.qty', 'l.unit_cost_minor']);
        if ($lines->isEmpty()) {
            return 0;
        }
        $billed = DB::table('supplier_bill_lines as b')->join('supplier_bills as s', 's.id', '=', 'b.supplier_bill_id')->where('s.status', '!=', 'rejected')
            ->whereIn('b.purchase_order_line_id', $lines->pluck('id'))->groupBy('b.purchase_order_line_id')->selectRaw('b.purchase_order_line_id, SUM(b.qty) AS q')
            ->pluck('q', 'purchase_order_line_id');
        $sum = 0;
        foreach ($lines as $l) {
            $open = Qty::toMilli((string) $l->qty) - Qty::toMilli((string) ($billed[$l->id] ?? '0'));
            $sum += $open > 0 ? Qty::divRound($open * (int) $l->unit_cost_minor, 1000) : 0;
        }

        return $sum;
    }

    /** One line for the order and the audit: "6110 Repairs: budget 5,000.00, spent 4,200.00, committed 300.00, this 900.00 → over by 400.00". */
    public static function describe(array $rows, string $currency): string
    {
        return implode('; ', array_map(fn ($r) => $r['account']->label().': '.($r['budget'] === 0 ? 'no budget set' : 'budget '.Money::format($r['budget'], $currency))
            .', spent '.Money::format($r['spent'], $currency).', committed '.Money::format($r['committed'], $currency).', this '.Money::format($r['this'], $currency)
            .' → '.($r['left'] < 0 ? 'over by '.Money::format(-$r['left'], $currency) : Money::format($r['left'], $currency).' left'), $rows));
    }
}
