<?php

namespace App\Domain\Services;

use App\Domain\Support\Actor;
use App\Domain\Support\Recorder;
use App\Models\Commission;
use App\Models\Sale;
use App\Support\Qty;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Commissions (Services): the staff member who did a service — or sold an item — earns a share of the line's price
 * before tax. The rate is the product's own commission rate, else the staff member's. A session paid from a pass is
 * worth the pass's value per session. Returns take the commission back. Commissions are paid with the month's payroll
 * (earning "Commission", taxed as income).
 */
final class CommissionService
{
    public function __construct(private readonly Recorder $recorder) {}

    /** Inside the sale's (or return's) transaction: one row per line with a staff member and a rate above zero. */
    public function record(Actor $actor, Sale $sale): void
    {
        $lines = DB::table('sale_lines as l')->join('employees as e', 'e.id', '=', 'l.staff_id')->join('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('service_passes as sp', 'sp.id', '=', 'l.service_pass_id')
            ->where('l.sale_id', $sale->id)->get(['l.id', 'l.product_id', 'l.qty', 'l.net_minor', 'l.staff_id', 'p.commission_bp as product_bp', 'e.commission_bp as staff_bp',
                'l.service_pass_id', 'sp.session_value_minor']);
        foreach ($lines as $l) {
            $rate = (int) ($l->product_bp ?? $l->staff_bp);
            // A session from a pass is a free line: its value is the pass's price per session.
            $base = $l->service_pass_id ? Qty::divRound((int) $l->session_value_minor * Qty::toMilli((string) $l->qty), 1000) : (int) $l->net_minor;
            $amount = Qty::divRound($base * $rate, 10000);
            if ($rate <= 0 || $amount === 0) {
                continue;
            }
            $row = (new Commission)->forceFill(['id' => (string) Str::uuid7(), 'tenant_id' => $sale->tenant_id, 'branch_id' => $sale->branch_id, 'employee_id' => $l->staff_id,
                'sale_id' => $sale->id, 'sale_line_id' => $l->id, 'product_id' => $l->product_id, 'base_minor' => $base, 'rate_bp' => $rate, 'amount_minor' => $amount,
                'occurred_at' => $sale->occurred_at, 'created_at' => now()]);
            $row->save();
            $this->recorder->outbox->publish($actor, $row, 'commission.earned');
        }
    }

    /** A staff member's commission for a period (local dates in $tz), for payroll. */
    public static function total(string $employeeId, Carbon $start, Carbon $end, string $tz): int
    {
        return (int) Commission::where('employee_id', $employeeId)
            ->where('occurred_at', '>=', $start->copy()->shiftTimezone($tz)->startOfDay()->utc())
            ->where('occurred_at', '<', $end->copy()->shiftTimezone($tz)->addDay()->startOfDay()->utc())->sum('amount_minor');
    }

    /** By staff member for a period: lines, sales value and commission. */
    public static function summary(string $from, string $to, string $tz, ?array $branchIds = null)
    {
        return DB::table('commissions as c')->join('employees as e', 'e.id', '=', 'c.employee_id')
            ->where('c.occurred_at', '>=', Carbon::parse($from, $tz)->startOfDay()->utc())->where('c.occurred_at', '<', Carbon::parse($to, $tz)->addDay()->startOfDay()->utc())
            ->when($branchIds !== null, fn ($q) => $q->whereIn('c.branch_id', $branchIds))
            ->groupBy('c.employee_id', 'e.name', 'e.number')->orderBy('e.name')
            ->selectRaw('c.employee_id, e.name, e.number, COUNT(*) AS lines, SUM(c.base_minor) AS base_minor, SUM(c.amount_minor) AS amount_minor')->get();
    }
}
