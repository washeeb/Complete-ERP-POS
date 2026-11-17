<?php

namespace App\Domain\Office;

use App\Domain\Pos\SaleDetails;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use App\Support\Money;
use App\Support\Qty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Till sales in the new back office (release 2 of the rewrite): the list with the same search and filters as the
 * current screen (receipt or teller, branch, today or dates, teller, paid by, amount) and one sale in full. Only sales of
 * the branches the person may see; sales never change here (returns come from the tills).
 */
final class SalesScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const SORTS = ['occurred_at', 'receipt_no', 'cashier_name', 'total_minor'];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return Erp::canAnywhere('sales.view');
    }

    private function query(): Builder
    {
        $ids = Erp::branchIds('sales.view');

        return Sale::query()->when($ids !== null, fn (Builder $q) => $q->whereIn('branch_id', $ids));
    }

    /** The sales the search and filters pick (also for the export). */
    public function filtered(array $p): Builder
    {
        $tz = $this->user->tenant?->timezone ?: 'UTC';
        $today = CarbonImmutable::now($tz)->startOfDay();
        $q = $this->query();
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn (Builder $w) => $w->where('receipt_no', 'ilike', "%{$search}%")->orWhere('cashier_name', 'ilike', "%{$search}%")
                ->orWhere('walk_in_name', 'ilike', "%{$search}%")->orWhere('walk_in_phone', 'ilike', "%{$search}%"));
        }
        if (filled($p['branch'] ?? null)) {
            $q->where('branch_id', (string) $p['branch']);
        }
        $period = (string) ($p['period'] ?? '');
        if ($period === 'today') {
            $q->where('occurred_at', '>=', $today->utc());
        } elseif (in_array($period, ['7', '30', '90'], true)) {
            $q->where('occurred_at', '>=', $today->subDays((int) $period - 1)->utc());
        }
        if (($from = $this->date($p['from'] ?? null, $tz)) !== null) {
            $q->where('occurred_at', '>=', $from->utc());
        }
        if (($to = $this->date($p['to'] ?? null, $tz)) !== null) {
            $q->where('occurred_at', '<', $to->addDay()->utc());
        }
        if (filled($p['teller'] ?? null)) {
            $q->where('cashier_id', (string) $p['teller']);
        }
        $methods = array_values(array_intersect((array) ($p['methods'] ?? []), array_keys(SalePayment::METHODS)));
        if ($methods) {
            $q->whereIn('sales.id', SalePayment::query()->select('sale_id')->whereIn('method', $methods));
        }
        $currency = $this->user->tenant?->currency_code ?? 'GHS';
        if (is_numeric($p['min'] ?? null)) {
            $q->where('total_minor', '>=', Money::toMinor((string) $p['min'], $currency));
        }
        if (is_numeric($p['max'] ?? null)) {
            $q->where('total_minor', '<=', Money::toMinor((string) $p['max'], $currency));
        }
        if (in_array($p['kind'] ?? null, ['sale', 'return'], true)) {
            $q->where('kind', $p['kind']);
        }

        return $q;
    }

    /** The order the list shows: [column, direction]. */
    public function order(array $p): array
    {
        return [in_array($p['sort'] ?? null, self::SORTS, true) ? $p['sort'] : 'occurred_at', ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc'];
    }

    /** The filtered sales in the list's order, with what a row shows loaded. */
    public function sorted(array $p): Builder
    {
        [$sort, $dir] = $this->order($p);

        return $this->filtered($p)->with(['branch:id,code,name', 'till:id,code,name', 'payments:id,sale_id,method,amount_minor'])->orderBy($sort, $dir)->orderBy('id', $dir);
    }

    /** One row of the list. */
    public function rowOf(Sale $s): array
    {
        return [
            'id' => $s->id, 'receipt_no' => $s->receipt_no, 'occurred_at' => $s->occurred_at?->toIso8601String(), 'kind' => $s->kind,
            'branch' => $s->branch?->code, 'till' => $s->till?->name, 'cashier' => $s->cashier_name, 'total' => (int) $s->total_minor,
            'paid_by' => $s->payments->pluck('method')->unique()->map(fn ($m) => SalePayment::METHODS[$m] ?? $m)->values()->all(),
        ];
    }

    /** @param  array<string, mixed>  $p  q, branch, period (today|7|30|90), from, to, teller, methods[], min, max, kind, sort, dir, page, per */
    public function list(array $p): array
    {
        $q = $this->filtered($p);
        $currency = $this->user->tenant?->currency_code ?? 'GHS';
        $totals = (clone $q)->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_minor), 0) AS s')->first();
        $mix = SalePayment::query()->whereIn('sale_id', (clone $q)->select('sales.id'))->groupBy('method')
            ->selectRaw('method, SUM(amount_minor) AS amount')->orderByDesc('amount')->limit(4)->get()
            ->map(fn ($r) => ['method' => SalePayment::METHODS[$r->method] ?? $r->method, 'amount' => (int) $r->amount])->all();

        [$sort, $dir] = $this->order($p);
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $rows = $this->sorted($p)->forPage($page, $per)->get();

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency),
            'total' => (int) $totals->n, 'sum' => (int) $totals->s, 'mix' => $mix, 'page' => $page, 'per' => $per, 'sort' => $sort, 'dir' => $dir,
            'rows' => $rows->map(fn (Sale $s) => $this->rowOf($s))->all(),
        ];
    }

    /** What the filters offer: branches the person may see, tellers who sold there, the ways of paying. */
    public function options(): array
    {
        $ids = Erp::branchIds('sales.view');

        return [
            'branches' => Branch::query()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($b) => ['id' => $b->id, 'label' => "{$b->code} · {$b->name}"])->all(),
            'tellers' => $this->query()->whereNotNull('cashier_id')->groupBy('cashier_id')->selectRaw('cashier_id, MAX(cashier_name) AS name')
                ->orderBy('name')->limit(300)->get()->map(fn ($r) => ['id' => $r->cashier_id, 'label' => $r->name])->all(),
            'methods' => collect(SalePayment::METHODS)->map(fn ($l, $k) => ['id' => $k, 'label' => $l])->values()->all(),
            'per' => self::PER_PAGE,
        ];
    }

    /** One sale in full: what the current screen shows, and what may be done with it. */
    public function show(Sale $s): array
    {
        $s->loadMissing(['branch', 'till', 'lines', 'payments']);
        $m = fn (?int $v) => (int) ($v ?? 0);
        $canSaveCustomer = ! $s->customer_id && $s->walk_in_phone && Erp::canAnywhere('customers.manage') && ! Customer::where('phone', $s->walk_in_phone)->exists();

        return [
            'currency' => $s->currency_code, 'exponent' => Money::exponent($s->currency_code),
            'id' => $s->id, 'receipt_no' => $s->receipt_no, 'occurred_at' => $s->occurred_at?->toIso8601String(), 'kind' => $s->kind, 'return_reason' => $s->return_reason,
            'cashier' => $s->cashier_name, 'where' => $s->till ? "{$s->branch?->code} · till {$s->till->code}" : "{$s->branch?->code} · back office",
            'net' => $m($s->net_minor), 'tax' => $m($s->tax_minor), 'total' => $m($s->total_minor), 'change' => $m($s->change_minor), 'discount' => $m($s->discount_minor),
            'discount_note' => trim(($s->coupon_code ? "coupon {$s->coupon_code} " : '').($s->discount_approved_by_name ? "approved by {$s->discount_approved_by_name}" : '')) ?: null,
            'points' => (int) $s->points_earned,
            'customer' => ['id' => $s->customer_id, 'walk_in_name' => $s->walk_in_name, 'walk_in_phone' => $s->walk_in_phone,
                'details' => collect($s->extras ?? [])->map(fn ($v, $k) => ['label' => SaleDetails::EXTRAS[$k][0] ?? $k, 'value' => is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v])->values()->all(),
                'comment' => $s->comment, 'comment_on_receipt' => (bool) $s->comment_on_receipt],
            'lines' => $s->lines->map(fn ($l) => ['name' => $l->name, 'qty' => rtrim(rtrim(Qty::format(Qty::toMilli((string) $l->qty)), '0'), '.').' '.$l->uom_code,
                'unit_price' => $m($l->unit_price_minor), 'tax' => $m($l->tax_minor), 'total' => $m($l->line_total_minor), 'dosage' => $l->dosage])->all(),
            'tax_breakdown' => collect($s->tax_breakdown ?? [])->map(fn ($t) => ['name' => (string) ($t['name'] ?? ''), 'amount' => (int) ($t['amount_minor'] ?? 0)])->all(),
            'payments' => $s->payments->map(fn ($p) => ['method' => (SalePayment::METHODS[$p->method] ?? $p->method).($p->provider ? ' · '.(SalePayment::MOMO_PROVIDERS[$p->provider] ?? $p->provider) : ''),
                'amount' => $m($p->amount_minor), 'reference' => $p->reference])->all(),
            'actions' => ['print' => route('print.receipt', ['sale' => $s->id, 'print' => 1]), 'preview' => route('print.receipt', $s->id), 'save_customer' => $canSaveCustomer],
        ];
    }

    private function date(mixed $v, string $tz): ?CarbonImmutable
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? CarbonImmutable::createFromFormat('Y-m-d', $v, $tz)->startOfDay() : null;
    }
}
