<?php

namespace App\Domain\Analytics;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cash-flow forecast, week by week: money now (cash, bank, mobile money, card and online settlements due) plus till
 * takings at the recent weekly average and customer invoices by due date, less supplier bills by due date, payroll at
 * the last run's cost at month end, and paid expense claims at their recent weekly average. Overdue items fall in the
 * first week. A guide for planning, not a promise.
 */
final class CashFlowForecaster
{
    public const MONEY = ['cash_till', 'cash_office', 'bank', 'momo', 'petty_cash', 'card_clearing', 'online_clearing'];

    /** @return array{opening_minor: int, weeks: list<array<string, mixed>>, lowest_minor: int, lowest_week: ?string} */
    public function forecast(string $tenantId, int $weeks = 8, ?CarbonImmutable $today = null): array
    {
        $tz = Tenant::find($tenantId)?->timezone ?: 'UTC';
        $today ??= CarbonImmutable::now($tz)->startOfDay();
        $opening = (int) DB::table('journal_lines as j')->join('accounts as a', 'a.id', '=', 'j.account_id')->where('a.tenant_id', $tenantId)
            ->whereIn('a.system_key', self::MONEY)->sum('j.amount_minor');
        // Money accounts the owner added themselves (other banks) sit under the same heading as the bank.
        $bankParent = DB::table('accounts')->where('tenant_id', $tenantId)->where('system_key', 'bank')->value('parent_id');
        if ($bankParent) {
            $opening += (int) DB::table('journal_lines as j')->join('accounts as a', 'a.id', '=', 'j.account_id')->where('a.parent_id', $bankParent)
                ->whereNull('a.system_key')->where('a.is_header', false)->sum('j.amount_minor');
        }

        $from8 = $today->subWeeks(8);
        $tillWeekly = (int) round(DB::table('sale_payments as p')->join('sales as s', 's.id', '=', 'p.sale_id')->where('s.tenant_id', $tenantId)->where('s.status', 'posted')
            ->whereIn('p.method', ['cash', 'momo', 'card', 'online'])->where('s.occurred_at', '>=', $from8->utc())->where('s.occurred_at', '<', $today->utc())->sum('p.amount_minor') / 8);
        $expenseWeekly = (int) round(DB::table('expense_claims')->where('tenant_id', $tenantId)->where('status', 'paid')
            ->where('paid_on', '>=', $from8->toDateString())->where('paid_on', '<', $today->toDateString())->sum('amount_minor') / 8);
        $lastRun = DB::table('payroll_runs')->where('tenant_id', $tenantId)->whereIn('status', ['approved', 'paid'])->orderByDesc('period_end')->value('totals');
        $totals = $lastRun ? json_decode($lastRun, true) : [];
        $payroll = (int) (($totals['gross_minor'] ?? 0) + ($totals['ssnit_employer_minor'] ?? 0));

        $receivable = $this->openByDue('customer_ledger', $tenantId);
        $payable = $this->openByDue('supplier_ledger', $tenantId);

        $rows = [];
        $balance = $opening;
        $lowest = [$opening, null];
        for ($w = 0; $w < $weeks; $w++) {
            $start = $today->addWeeks($w);
            $end = $start->addDays(6);
            $due = fn (array $items) => (int) array_sum(array_map(fn ($i) => $i['amount'], array_filter($items, fn ($i) => ($w === 0 && $i['due'] < $start) || ($i['due'] >= $start && $i['due'] <= $end))));
            $in = $tillWeekly + $due($receivable);
            $payrollOut = $start->month !== $end->month || $end->isLastOfMonth() ? $payroll : 0; // salaries at month end
            $out = $due($payable) + $payrollOut + $expenseWeekly;
            $close = $balance + $in - $out;
            $rows[] = ['week' => $start->toDateString(), 'opening_minor' => $balance, 'till_minor' => $tillWeekly, 'customers_minor' => $due($receivable),
                'suppliers_minor' => $due($payable), 'payroll_minor' => $payrollOut, 'expenses_minor' => $expenseWeekly, 'closing_minor' => $close];
            if ($close < $lowest[0]) {
                $lowest = [$close, $start->toDateString()];
            }
            $balance = $close;
        }

        return ['opening_minor' => $opening, 'weeks' => $rows, 'lowest_minor' => $lowest[0], 'lowest_week' => $lowest[1]];
    }

    /**
     * Open balances per document with their due dates (charges less what was allocated against them), from the party
     * ledger.
     *
     * @return list<array{due: CarbonImmutable, amount: int}>
     */
    private function openByDue(string $table, string $tenantId): array
    {
        // Each party's balance spread over its charges, latest first (payments clear the oldest charges first).
        $party = $table === 'customer_ledger' ? 'customer_id' : 'supplier_id';
        $balances = DB::table($table)->where('tenant_id', $tenantId)->groupBy($party)->selectRaw("{$party} AS party, SUM(amount_minor) AS bal")->pluck('bal', 'party');
        $out = [];
        foreach ($balances as $id => $bal) {
            $left = (int) $bal; // both ledgers: + is owed (to us by customers, by us to suppliers)
            if ($left <= 0) {
                continue;
            }
            $charges = DB::table($table)->where($party, $id)->where('amount_minor', '>', 0)
                ->orderByDesc('occurred_at')->get(['amount_minor', 'due_on', 'occurred_at']);
            foreach ($charges as $c) {
                if ($left <= 0) {
                    break;
                }
                $amount = min($left, abs((int) $c->amount_minor));
                $out[] = ['due' => CarbonImmutable::parse($c->due_on ?? $c->occurred_at)->startOfDay(), 'amount' => $amount];
                $left -= $amount;
            }
        }

        return $out;
    }
}
