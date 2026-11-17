<?php

namespace App\Domain\Office;

use App\Domain\Pos\CashControl;
use App\Domain\Pos\ShiftService;
use App\Filament\Resources\Shifts\ShiftResource;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\Shift;
use App\Models\TillDrawerEvent;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Till shifts and the till drawer in the new back office (release 2, part 2). The figures are the current screens' own
 * (ShiftResource: takings, the drawer and the MoMo/card/voucher checks, a corrected count, the shift report), so both
 * always agree. Only shifts of the branches the person may see.
 */
final class ShiftsScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return Erp::canAnywhere('sales.view');
    }

    private function scope(Builder $q): Builder
    {
        $ids = Erp::branchIds('sales.view');

        return $q->when($ids !== null, fn (Builder $w) => $w->whereIn('branch_id', $ids));
    }

    private function tz(): string
    {
        return $this->user->tenant?->timezone ?: 'UTC';
    }

    /** The corrected over/short of `shifts`: the latest correction's, or the shift's own. */
    private const OS = 'COALESCE((SELECT c.over_short_after_minor FROM shift_count_corrections c WHERE c.shift_id = shifts.id ORDER BY c.corrected_at DESC LIMIT 1), shifts.over_short_minor)';

    /** The shifts the filters pick (also for the export). */
    public function shiftsFiltered(array $p): Builder
    {
        $q = $this->scope(Shift::query());
        if (in_array($p['status'] ?? null, ['open', 'closed'], true)) {
            $q->where('status', $p['status']);
        }
        if (filled($p['branch'] ?? null)) {
            $q->where('branch_id', (string) $p['branch']);
        }
        if (filled($p['teller'] ?? null)) {
            $q->where('opened_by', (string) $p['teller']);
        }
        $this->dates($q, 'opened_at', $p);
        $currency = $this->user->tenant?->currency_code ?? 'GHS';
        $os = self::OS;
        if (is_numeric($p['os_min'] ?? null)) {
            $q->whereRaw("{$os} >= ?", [Money::toMinor((string) $p['os_min'], $currency)]);
        }
        if (is_numeric($p['os_max'] ?? null)) {
            $q->whereRaw("{$os} <= ?", [Money::toMinor((string) $p['os_max'], $currency)]);
        }
        if (! empty($p['vault_waiting'])) {
            $q->whereNotNull('vault_id')->where('status', 'closed')->whereNull('checked_at');
        }

        return $q;
    }

    /** The filtered shifts in the list's order, with what a row shows loaded. */
    public function shiftsSorted(array $p): Builder
    {
        $sort = ($p['sort'] ?? '') === 'over_short' ? 'over_short' : 'opened_at';
        $dir = ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $this->shiftsFiltered($p)->with('branch', 'till', 'vault', 'latestCorrection', 'drawerEvents')->withCount('sales')
            ->when($sort === 'over_short', fn ($w) => $w->orderByRaw(self::OS." {$dir} NULLS LAST"), fn ($w) => $w->orderBy('opened_at', $dir))
            ->orderBy('id', $dir);
    }

    /** @param  array<string, mixed>  $p  status, branch, teller, period, from, to, os_min, os_max, vault_waiting, sort, dir, page, per */
    public function shifts(array $p): array
    {
        $q = $this->shiftsFiltered($p);
        $os = self::OS;
        $currency = $this->user->tenant?->currency_code ?? 'GHS';
        $totals = (clone $q)->selectRaw("COUNT(*) AS n, SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open, COALESCE(SUM({$os}), 0) AS os")->first();
        $sort = ($p['sort'] ?? '') === 'over_short' ? 'over_short' : 'opened_at';
        $dir = ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $rows = $this->shiftsSorted($p)->forPage($page, $per)->get();

        return ['currency' => $currency, 'exponent' => Money::exponent($currency), 'total' => (int) $totals->n, 'open' => (int) $totals->open,
            'over_short' => (int) $totals->os, 'page' => $page, 'per' => $per, 'sort' => $sort, 'dir' => $dir,
            'rows' => $rows->map(fn (Shift $s) => $this->row($s))->all()];
    }

    /** One row of the list. */
    public function row(Shift $s): array
    {
        $t = app(ShiftService::class)->takings($s);

        return [
            'id' => $s->id, 'where' => "{$s->branch?->code} · {$s->till?->code}", 'status' => $s->status, 'shift_name' => $s->shift_name,
            'opened_by' => $s->opened_by_name, 'sellers' => ShiftResource::sellers($s), 'opened_at' => $s->opened_at?->toIso8601String(),
            'closed_at' => $s->closed_at?->toIso8601String(), 'left_open' => ShiftResource::leftOpen($s),
            'vault' => $s->vault?->name, 'vault_waiting' => $s->vault_id && ! $s->isOpen() && ! $s->checked_at,
            'sales' => (int) $s->sales_count, 'takings' => (int) $t['total_minor'],
            'float' => (int) $s->opening_float_minor, 'expected' => $s->expected_cash_minor === null ? null : (int) $s->expected_cash_minor,
            'counted' => ($c = $s->latestCorrection?->counted_after_minor ?? $s->checked_count_minor ?? $s->counted_cash_minor) === null ? null : (int) $c,
            'corrected' => (bool) $s->latestCorrection,
            'over_short' => ($o = $s->latestCorrection?->over_short_after_minor ?? $s->over_short_minor) === null ? null : (int) $o,
            'drawer' => ShiftResource::drawerSummary($s), 'checks' => ShiftResource::otherSummary($s), 'note' => $s->close_note,
        ];
    }

    /** One shift in full: the shift report's own figures and tables, and what may be done with it. */
    public function shift(Shift $s): array
    {
        $s->loadMissing('branch', 'till', 'vault', 'latestCorrection', 'drawerEvents');
        $branch = Branch::find($s->branch_id);
        $canSetup = Erp::can('cash.setup', $branch);
        ['intro' => $intro, 'sheets' => $sheets] = ShiftResource::reportContent($s);

        return $this->row($s->loadCount('sales')) + [
            'currency' => $s->currency_code, 'exponent' => Money::exponent($s->currency_code),
            'tiles' => $intro['tiles'], 'facts' => $intro['facts'], 'branch' => $intro['branch'],
            'sheets' => array_values(array_filter($sheets, fn ($sh) => ! (($sh['skip_empty'] ?? false) && $sh['rows'] === []))),
            'expected_now' => $s->isOpen() ? app(ShiftService::class)->expectedCash($s) : null,
            // The vault count is blind: the notes and coins to count, never the figure it is checked against.
            'denominations' => collect(CashControl::denominations($s->currency_code))->map(fn ($d, $key) => ['key' => $key, 'label' => preg_replace('/ (note|coin)$/', '', $d[0]),
                'value' => $d[1], 'kind' => str_starts_with($key, 'n') ? 'note' : 'coin'])->values()->all(),
            'vault_recounts' => (int) $s->check_recounts,
            'actions' => [
                'report' => ! $s->isOpen(),
                'close' => $s->isOpen() && ! $s->vault_session_id && $canSetup,
                'correct' => ! $s->isOpen() && ! $s->vault_session_id && $canSetup,
                'count_vault' => $s->vault_id && ! $s->isOpen() && ! $s->checked_at && Erp::can('cash.vault.check', $branch),
            ],
        ];
    }

    /** The drawer events the filters pick (also for the export). */
    public function drawerFiltered(array $p): Builder
    {
        $q = $this->scope(TillDrawerEvent::query());
        if (isset(TillDrawerEvent::KINDS[$p['kind'] ?? ''])) {
            $q->where('kind', $p['kind']);
        }
        if (filled($p['branch'] ?? null)) {
            $q->where('branch_id', (string) $p['branch']);
        }
        if (filled($p['person'] ?? null)) {
            $q->where('user_id', (string) $p['person']);
        }
        $this->dates($q, 'occurred_at', $p);

        return $q;
    }

    public function drawerSorted(array $p): Builder
    {
        $dir = ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $this->drawerFiltered($p)->with('account', 'shift.till', 'shift.branch')->orderBy('occurred_at', $dir)->orderBy('id', $dir);
    }

    /** One row of the drawer list. */
    public function drawerRow(TillDrawerEvent $e): array
    {
        return [
            'id' => $e->id, 'occurred_at' => $e->occurred_at?->toIso8601String(), 'where' => "{$e->shift?->branch?->code} · {$e->shift?->till?->code}",
            'kind' => $e->kind, 'kind_label' => TillDrawerEvent::KINDS[$e->kind] ?? $e->kind, 'amount' => $e->kind === 'no_sale' ? null : (int) $e->amount_minor,
            'spent_on' => $e->account?->name, 'reason' => $e->reason, 'payee' => $e->payee, 'by' => $e->user_name, 'approved_by' => $e->approved_by_name,
            'receipt' => $e->expense_claim_id && $e->receipt_path ? route('expense.receipt', $e->expense_claim_id) : null, 'offline' => (bool) $e->offline,
            'shift_id' => $e->shift_id,
        ];
    }

    /** @param  array<string, mixed>  $p  kind, branch, person, period, from, to, page, per */
    public function drawer(array $p): array
    {
        $q = $this->drawerFiltered($p);
        $sums = (clone $q)->groupBy('kind')->selectRaw('kind, COUNT(*) AS n, COALESCE(SUM(amount_minor), 0) AS s')->get()->keyBy('kind');
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $dir = ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $rows = $this->drawerSorted($p)->forPage($page, $per)->get();
        $currency = $this->user->tenant?->currency_code ?? 'GHS';

        return ['currency' => $currency, 'exponent' => Money::exponent($currency), 'total' => (int) $sums->sum('n'), 'page' => $page, 'per' => $per, 'dir' => $dir,
            'kinds' => collect(TillDrawerEvent::KINDS)->map(fn ($label, $k) => ['id' => $k, 'label' => $label, 'count' => (int) ($sums[$k]->n ?? 0),
                'amount' => (int) ($sums[$k]->s ?? 0)])->values()->all(),
            'rows' => $rows->map(fn (TillDrawerEvent $e) => $this->drawerRow($e))->all()];
    }

    /** Branches, tellers (who opened shifts) and people at the drawer, for the filters. */
    public function options(): array
    {
        $ids = Erp::branchIds('sales.view');

        return [
            'branches' => Branch::query()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($b) => ['id' => $b->id, 'label' => "{$b->code} · {$b->name}"])->all(),
            'tellers' => $this->scope(Shift::query())->whereNotNull('opened_by')->groupBy('opened_by')->selectRaw('opened_by AS id, MAX(opened_by_name) AS label')
                ->orderBy('label')->limit(300)->get()->map(fn ($r) => ['id' => $r->id, 'label' => $r->label])->all(),
            'people' => $this->scope(TillDrawerEvent::query())->whereNotNull('user_id')->groupBy('user_id')->selectRaw('user_id AS id, MAX(user_name) AS label')
                ->orderBy('label')->limit(300)->get()->map(fn ($r) => ['id' => $r->id, 'label' => $r->label])->all(),
            'kinds' => collect(TillDrawerEvent::KINDS)->map(fn ($l, $k) => ['id' => $k, 'label' => $l])->values()->all(),
            'per' => self::PER_PAGE,
        ];
    }

    private function dates(Builder $q, string $col, array $p): void
    {
        $today = CarbonImmutable::now($this->tz())->startOfDay();
        $period = (string) ($p['period'] ?? '');
        if ($period === 'today') {
            $q->where($col, '>=', $today->utc());
        } elseif (in_array($period, ['7', '30', '90'], true)) {
            $q->where($col, '>=', $today->subDays((int) $period - 1)->utc());
        }
        foreach (['from' => '>=', 'to' => '<'] as $k => $op) {
            $v = $p[$k] ?? null;
            if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $d = CarbonImmutable::createFromFormat('Y-m-d', $v, $this->tz())->startOfDay();
                $q->where($col, $op, ($k === 'to' ? $d->addDay() : $d)->utc());
            }
        }
    }
}
