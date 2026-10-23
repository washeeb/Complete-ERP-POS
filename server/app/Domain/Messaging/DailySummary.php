<?php

namespace App\Domain\Messaging;

use App\Domain\Inventory\StockBatches;
use App\Domain\Inventory\StockChecks;
use App\Domain\Purchasing\ReorderService;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Money;
use App\Support\Settings;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The owner's daily summary (2026-10-23): one message each evening with the day in the company — sales by branch against
 * the same day last week, cash / mobile money / card, cash over or short at the till closes, low stock, expired and
 * expiring stock, today's losses, unusual losses, and what is waiting for approval. To the people with "receive the
 * daily summary" (owner and general manager by default): on the bell, by e-mail and, when set up, a short WhatsApp.
 */
final class DailySummary
{
    public const PERMISSION = 'reports.daily_summary';

    public function __construct(private readonly Settings $settings, private readonly Authorizer $authz, private readonly Messenger $messenger) {}

    /** Run every few minutes: each company's summary goes once a day, after its time. @return int people told */
    public function sendDue(?Carbon $now = null): int
    {
        $told = 0;
        foreach (Tenant::query()->get() as $tenant) {
            if (! $this->settings->get('summary.daily', $tenant->id) || Tenant::isPractice($tenant->id)) {
                continue;
            }
            $local = ($now ?? now())->copy()->setTimezone($tenant->timezone ?: 'UTC');
            $at = (string) $this->settings->get('summary.time', $tenant->id);
            $at = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $at) ? $at : '20:00';
            $key = "daily-summary:{$tenant->id}:{$local->toDateString()}";
            if ($local->format('H:i') < $at || Cache::has($key)) {
                continue;
            }
            Cache::put($key, true, now()->addDays(2));
            try {
                $told += $this->send($tenant, $local);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $told;
    }

    public function send(Tenant $tenant, Carbon $day): int
    {
        $people = User::where('tenant_id', $tenant->id)->where('is_active', true)->get()->filter(fn (User $u) => $this->authz->allowsAnywhere($u, self::PERMISSION))->values();
        if ($people->isEmpty()) {
            return 0;
        }
        $s = $this->build($tenant, $day);
        Notification::make()->title($s['title'])->body($s['text'])->info()->sendToDatabase($people);
        foreach (['email' => 'summary.by_email', 'whatsapp' => 'summary.by_whatsapp'] as $channel => $setting) {
            $t = $this->settings->get($setting, $tenant->id) ? Templates::find($tenant->id, 'daily_summary', $channel) : null;
            if (! $t?->is_active) {
                continue;
            }
            foreach ($people as $u) {
                $this->messenger->later($tenant->id, $channel, $channel === 'email' ? $u->email : $u->phone, $t,
                    ['company' => $tenant->name, 'date' => $s['date'], 'sales' => $s['sales'], 'text' => $s['text']], 'SUMMARY-'.$day->format('Ymd'), 'Daily summary');
            }
        }

        return $people->count();
    }

    /** @return array{title: string, date: string, sales: string, text: string} */
    public function build(Tenant $tenant, Carbon $day): array
    {
        $tz = $tenant->timezone ?: 'UTC';
        $from = $day->copy()->setTimezone($tz)->startOfDay()->utc();
        $to = $day->copy()->setTimezone($tz)->endOfDay()->utc();
        $m = fn (int $v) => Money::display($v, $tenant->currency_code);
        $sales = fn (Carbon $a, Carbon $b) => DB::table('sales')->where('tenant_id', $tenant->id)->where('status', 'posted')->whereBetween('occurred_at', [$a, $b]);
        $branches = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('code')->get(['id', 'name']);

        $lines = [];
        $total = (int) $sales($from, $to)->sum('total_minor'); // returns are negative: this is the net
        $lastWeek = (int) $sales($from->copy()->subWeek(), $to->copy()->subWeek())->sum('total_minor');
        $change = $lastWeek ? ' ('.($total >= $lastWeek ? '+' : '').round(($total - $lastWeek) * 100 / $lastWeek).' % on last '.$day->format('l').')' : '';
        $lines[] = "Sales: {$m($total)}{$change}, ".$sales($from, $to)->where('kind', 'sale')->count().' sale(s)';
        $byBranch = $sales($from, $to)->groupBy('branch_id')->selectRaw('branch_id, SUM(total_minor) AS t')->pluck('t', 'branch_id');
        foreach ($branches as $b) {
            if (isset($byBranch[$b->id])) {
                $lines[] = "  {$b->name}: {$m((int) $byBranch[$b->id])}";
            }
        }
        $pay = DB::table('sale_payments as p')->join('sales as s', 's.id', '=', 'p.sale_id')->where('s.tenant_id', $tenant->id)->where('s.status', 'posted')
            ->whereBetween('s.occurred_at', [$from, $to])->groupBy('p.method')->selectRaw('p.method, SUM(p.amount_minor) AS t')->pluck('t', 'method');
        $lines[] = 'Cash '.$m((int) ($pay['cash'] ?? 0)).' · Mobile money '.$m((int) ($pay['momo'] ?? 0)).' · Card '.$m((int) ($pay['card'] ?? 0));
        $shifts = DB::table('shifts')->where('tenant_id', $tenant->id)->where('status', 'closed')->whereBetween('closed_at', [$from, $to]);
        $overShort = (int) (clone $shifts)->sum('over_short_minor');
        $lines[] = 'Till closes: '.(clone $shifts)->count().', cash '.($overShort === 0 ? 'balanced' : ($overShort > 0 ? 'over ' : 'short ').$m(abs($overShort)));

        $warehouses = Warehouse::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get();
        $low = $warehouses->sum(fn ($w) => count(app(ReorderService::class)->suggestions($w)));
        $batches = fn () => DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')->where('b.tenant_id', $tenant->id)->where('l.on_hand', '>', 0);
        $today = StockBatches::today();
        $lines[] = "Stock: {$low} product(s) low, ".$batches()->where('b.expires_on', '<', $today)->count().' batch(es) expired still in stock, '
            .$batches()->whereBetween('b.expires_on', [$today, now()->addDays(30)->toDateString()])->count().' expiring within 30 days';
        $loss = (int) DB::table('stock_movements')->where('tenant_id', $tenant->id)->whereIn('type', ['adjustment', 'count'])->where('qty_base', '<', 0)
            ->whereBetween('occurred_at', [$from, $to])->selectRaw('-SUM(ROUND(qty_entered * COALESCE(unit_cost_minor, 0)))::bigint AS v')->value('v');
        $unusual = $warehouses->sum(fn ($w) => app(StockChecks::class)->largerThanUsual($w->id, $day)->count());
        $lines[] = 'Losses today: '.$m($loss).($unusual ? " ({$unusual} larger than usual in the last 30 days — Stock → My day)" : '');

        $waiting = [
            'purchase order(s)' => DB::table('purchase_orders')->where('tenant_id', $tenant->id)->where('status', 'pending_approval')->count(),
            'stock adjustment(s)' => DB::table('stock_adjustments')->where('tenant_id', $tenant->id)->where('status', 'submitted')->count(),
            'stock count(s)' => DB::table('stock_counts')->where('tenant_id', $tenant->id)->where('status', 'submitted')->count(),
            'expense claim(s)' => DB::table('expense_claims')->where('tenant_id', $tenant->id)->where('status', 'submitted')->count(),
        ];
        $waiting = array_filter($waiting);
        $lines[] = $waiting ? 'Waiting for approval: '.collect($waiting)->map(fn ($n, $what) => "{$n} {$what}")->implode(', ') : 'Nothing waiting for approval.';

        $date = $day->copy()->setTimezone($tz)->format('D j M Y');

        return ['title' => "{$tenant->name} — {$date}", 'date' => $date, 'sales' => $m($total), 'text' => implode("\n", $lines)];
    }
}
