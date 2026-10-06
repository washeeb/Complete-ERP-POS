<?php

namespace App\Domain\Budgets;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Account;
use App\Models\Branch;
use App\Security\Authorizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Budgets per branch × income/expense account × month of the financial year (owner decision 2026-10-13).
 * Amounts are entered as positive numbers for both income and costs; the report compares them with the ledger.
 */
final class BudgetService
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz) {}

    /** @param array<string, list<int>> $rows account id → 12 monthly amounts (minor units); a row of zeros removes the budget line */
    public function save(Actor $actor, int $year, Branch $branch, array $rows): int
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'budgets.manage')) {
            throw new AccessDenied;
        }
        $accounts = Account::whereIn('id', array_keys($rows))->get()->keyBy('id');

        return $this->recorder->transaction(function () use ($actor, $year, $branch, $rows, $accounts) {
            $n = 0;
            foreach ($rows as $accountId => $months) {
                $a = $accounts[$accountId] ?? throw new DomainException('Unknown account.');
                if ($a->isBalanceSheet() || $a->is_header) {
                    throw new DomainException("Budgets are for income and expense accounts, not {$a->label()}.");
                }
                $months = array_values(array_pad(array_map('intval', $months), 12, 0));
                foreach ($months as $i => $amount) {
                    if ($amount < 0) {
                        throw new DomainException("{$a->label()}: enter budgets as positive amounts.");
                    }
                    $key = ['tenant_id' => $actor->tenantId, 'year' => $year, 'period' => $i + 1, 'branch_id' => $branch->id, 'account_id' => $a->id];
                    if ($amount === 0) {
                        $n += DB::table('budget_amounts')->where($key)->delete();
                    } else {
                        $n += DB::table('budget_amounts')->upsert([$key + ['id' => (string) Str::uuid7(), 'amount_minor' => $amount, 'created_at' => now(), 'updated_at' => now()]],
                            ['tenant_id', 'year', 'period', 'branch_id', 'account_id'], ['amount_minor', 'updated_at']) ? 1 : 0;
                    }
                }
            }
            $this->recorder->audit->recordRef($actor, 'budget.saved', 'branch', $branch->id, ['year' => [null, $year], 'accounts' => [null, count($rows)]]);

            return $n;
        });
    }

    /** @return array<string, list<int>> account id → 12 monthly amounts */
    public function load(string $tenantId, int $year, string $branchId): array
    {
        $out = [];
        foreach (DB::table('budget_amounts')->where('tenant_id', $tenantId)->where('year', $year)->where('branch_id', $branchId)->get() as $r) {
            $out[$r->account_id] ??= array_fill(0, 12, 0);
            $out[$r->account_id][$r->period - 1] = (int) $r->amount_minor;
        }

        return $out;
    }

    /** Spread an annual amount over 12 months; the last month takes the rounding. */
    public static function spread(int $annual): array
    {
        $m = intdiv($annual, 12);

        return [...array_fill(0, 11, $m), $annual - 11 * $m];
    }
}
