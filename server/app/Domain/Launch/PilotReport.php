<?php

namespace App\Domain\Launch;

use App\Domain\Support\SystemHealth;
use App\Mail\PlainMessage;
use App\Models\Branch;
use App\Models\GoLivePlan;
use App\Models\ParallelDay;
use App\Models\SupportReport;
use App\Models\Tenant;
use App\Support\Money;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The daily pilot report (Phase 14): what support needs to see each evening of a pilot — sales per pilot branch, cash
 * over/short, sales that reached the server late (a till was offline), the parallel-run difference, problems staff
 * reported, and the health checks that are not green. Sent at the plan's time while the pilot runs (parallel run, and
 * the first 60 days live); `erp:pilot-report --now` sends it at once.
 */
final class PilotReport
{
    public const PILOT_DAYS_AFTER_LIVE = 60;

    public function __construct(private readonly GoLiveService $golive, private readonly SystemHealth $health) {}

    /** @return array{title: string, lines: list<string>, warnings: list<string>} */
    public function build(GoLivePlan $plan, ?string $date = null): array
    {
        $tenant = Tenant::findOrFail($plan->tenant_id);
        app(TenantContext::class)->set($tenant->id);
        $tz = $tenant->timezone ?: 'UTC';
        $day = $date ?: now($tz)->toDateString();
        $from = Carbon::parse($day, $tz)->startOfDay()->utc();
        $to = $from->copy()->addDay();
        $money = fn (int $m) => Money::format($m, $tenant->currency_code);
        $branches = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->when($plan->pilot_branch_ids, fn ($q) => $q->whereIn('id', $plan->pilot_branch_ids))->orderBy('code')->get();

        $lines = [];
        $warnings = [];
        foreach ($branches as $b) {
            $t = $this->golive->dayTotals($b, $day);
            $lines[] = "{$b->code} {$b->name}: {$t['receipts']} receipt(s), sales {$money($t['sales'])}, cash {$money($t['cash'])}.";
            $os = (int) DB::table('shifts')->where('branch_id', $b->id)->where('status', 'closed')->where('closed_at', '>=', $from)->where('closed_at', '<', $to)->sum('over_short_minor');
            if ($os !== 0) {
                $warnings[] = "{$b->code}: tills closed {$money(abs($os))} ".($os < 0 ? 'short' : 'over').'.';
            }
            $late = DB::table('sales')->where('branch_id', $b->id)->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to)
                ->whereRaw("received_at - occurred_at > interval '1 hour'")->count();
            if ($late) {
                $warnings[] = "{$b->code}: {$late} sale(s) reached the server more than an hour late (a till was offline).";
            }
            $open = DB::table('shifts')->where('branch_id', $b->id)->where('status', 'open')->where('opened_at', '<', $from)->count();
            if ($open) {
                $warnings[] = "{$b->code}: {$open} till shift(s) left open from an earlier day.";
            }
            if ($p = ParallelDay::withoutGlobalScopes()->where('branch_id', $b->id)->whereDate('day', $day)->first()) {
                $d = $p->differenceMinor();
                $lines[] = "  Parallel run: old system {$money($p->old_sales_minor)}, difference {$money(abs($d))}".($d ? ($d > 0 ? ' more here' : ' less here') : '').($p->note ? " — {$p->note}" : '').'.';
                if (abs($d) > GoLiveService::PARALLEL_TOLERANCE_MINOR && trim((string) $p->note) === '') {
                    $warnings[] = "{$b->code}: the parallel run differs by {$money(abs($d))} with no explanation.";
                }
            }
        }
        $newReports = SupportReport::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('created_at', '>=', $from)->where('created_at', '<', $to)->get();
        $stillOpen = SupportReport::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', 'open')->count();
        $lines[] = "Problems reported today: {$newReports->count()} (still open in total: {$stillOpen}).";
        foreach ($newReports->take(10) as $r) {
            $lines[] = "  {$r->number} {$r->user_name}: ".mb_strimwidth(str_replace("\n", ' ', $r->message), 0, 120, '…');
        }
        try {
            foreach ($this->health->run() as $c) {
                if ($c['status'] !== 'ok') {
                    $warnings[] = "Health — {$c['check']}: {$c['detail']}";
                }
            }
        } catch (Throwable $e) {
            $warnings[] = 'Health checks could not run: '.$e->getMessage();
        }
        $errors = count(SupportReports::recentErrors(50));
        if ($errors) {
            $warnings[] = "{$errors} error line(s) in the server log in the last 24 hours.";
        }

        return ['title' => "Pilot report {$tenant->name} — ".Carbon::parse($day)->format('D j M Y').' ('.GoLivePlan::STATUSES[$plan->status].')', 'lines' => $lines, 'warnings' => $warnings];
    }

    /** Plans whose report is due now (and not sent today). */
    public function due(): array
    {
        $out = [];
        foreach (GoLivePlan::withoutGlobalScopes()->whereIn('status', ['parallel', 'live'])->get() as $plan) {
            $tz = Tenant::find($plan->tenant_id)?->timezone ?: 'UTC';
            $now = now($tz);
            if ($plan->status === 'live' && $plan->live_at && $plan->live_at->lt(now()->subDays(self::PILOT_DAYS_AFTER_LIVE))) {
                continue;
            }
            if ($now->format('H:i') >= $plan->pilot_report_time && $plan->pilot_report_sent_on?->toDateString() !== $now->toDateString()) {
                $out[] = $plan;
            }
        }

        return $out;
    }

    /** Build and e-mail one plan's report; returns the report (also when there is no address to send it to). */
    public function send(GoLivePlan $plan, ?string $date = null): array
    {
        $r = $this->build($plan, $date);
        $text = $r['title']."\n\n".implode("\n", $r['lines'])."\n\n".($r['warnings'] ? "Needs attention:\n- ".implode("\n- ", $r['warnings']) : 'Nothing needs attention.')."\n";
        $to = SupportReports::address($plan->tenant_id);
        $r['sent_to'] = null;
        if ($to) {
            try {
                Mail::to($to)->send(new PlainMessage(($r['warnings'] ? '[attention] ' : '').$r['title'], $text));
                $r['sent_to'] = $to;
            } catch (Throwable $e) {
                Log::warning('Pilot report not e-mailed', ['tenant' => $plan->tenant_id, 'error' => $e->getMessage()]);
                $r['error'] = $e->getMessage();
            }
        }
        $tz = Tenant::find($plan->tenant_id)?->timezone ?: 'UTC';
        $plan->forceFill(['pilot_report_sent_on' => now($tz)->toDateString()])->saveQuietly(); // bookkeeping of the sending job, not a business change
        $r['text'] = $text;

        return $r;
    }
}
