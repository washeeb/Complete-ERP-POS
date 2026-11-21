<?php

namespace App\Domain\Office;

use App\Domain\Customers\CustomerLedger;
use App\Domain\Sales\ReceivablesService;
use App\Filament\Pages\AgedReceivables;
use App\Filament\Resources\CustomerPayments\CustomerPaymentResource;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\SalePayment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payments received and Aged receivables in the new back office (part 15 of the rewrite), as the current pages: every
 * payment received from customers (search by receipt, customer or reference; method, received by, amount, dates; the
 * receipt to print) and what each customer owes by how late. Same queries: CustomerPaymentResource (the branches the
 * person may see for receivables) and CustomerLedger::aging.
 */
final class ReceivablesScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const SORTS = ['received_on', 'amount_minor', 'number'];

    public function __construct(private readonly User $user) {}

    public static function canViewPayments(): bool
    {
        return CustomerPaymentResource::canViewAny();
    }

    public static function canViewAging(): bool
    {
        return AgedReceivables::canAccess();
    }

    public function filtered(array $p): Builder
    {
        $q = CustomerPaymentResource::getEloquentQuery();
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn (Builder $w) => $w->where('customer_payments.number', 'ilike', "%{$search}%")->orWhere('customer_payments.reference', 'ilike', "%{$search}%")
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'ilike', "%{$search}%")));
        }
        $methods = array_values(array_intersect((array) ($p['method'] ?? []), array_keys(ReceivablesService::METHODS)));
        if ($methods) {
            $q->whereIn('customer_payments.method', $methods);
        }
        if (filled($p['by'] ?? null)) {
            $q->where('customer_payments.received_by_name', (string) $p['by']);
        }
        $cur = $this->currency();
        foreach (['min' => '>=', 'max' => '<='] as $k => $op) {
            if (is_numeric($p[$k] ?? null)) {
                $q->where('customer_payments.amount_minor', $op, Money::toMinor((string) $p[$k], $cur));
            }
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if (is_string($p[$k] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $p[$k])) {
                $q->whereDate('customer_payments.received_on', $op, $p[$k]);
            }
        }

        return $q;
    }

    /** @return array{0: string, 1: string} */
    public function order(array $p): array
    {
        return [in_array($p['sort'] ?? null, self::SORTS, true) ? $p['sort'] : 'received_on', ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc'];
    }

    public function sorted(array $p): Builder
    {
        [$sort, $dir] = $this->order($p);

        return $this->filtered($p)->orderBy("customer_payments.{$sort}", $dir)->orderBy('customer_payments.id', $dir);
    }

    public static function method(CustomerPayment $c): string
    {
        return (ReceivablesService::METHODS[$c->method] ?? $c->method).($c->provider ? ' · '.(SalePayment::MOMO_PROVIDERS[$c->provider] ?? $c->provider) : '');
    }

    public function rowOf(CustomerPayment $c): array
    {
        return ['id' => $c->id, 'number' => $c->number, 'received_on' => $c->received_on?->toDateString(), 'customer' => $c->customer?->name,
            'customer_id' => $c->customer_id, 'method' => self::method($c), 'reference' => $c->reference, 'amount' => (int) $c->amount_minor,
            'currency' => $c->currency_code, 'by' => $c->received_by_name, 'print' => route('print.payment', $c->id, false)];
    }

    /** @param  array<string, mixed>  $p  q, method[], by, min, max, from, to, sort, dir, page, per */
    public function payments(array $p): array
    {
        $t = (clone $this->filtered($p))->toBase()->reorder()->selectRaw('COUNT(*) AS n, COALESCE(SUM(amount_minor), 0) AS sum')->first();
        [$sort, $dir] = $this->order($p);
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $cur = $this->currency();

        return ['currency' => $cur, 'exponent' => Money::exponent($cur), 'total' => (int) $t->n, 'sum' => (int) $t->sum, 'page' => $page, 'per' => $per,
            'sort' => $sort, 'dir' => $dir, 'open_customer' => CustomersScreen::canView(),
            'rows' => $this->sorted($p)->with('customer')->forPage($page, $per)->get()->map(fn (CustomerPayment $c) => $this->rowOf($c))->all()];
    }

    public function options(): array
    {
        return ['methods' => self::choices(ReceivablesService::METHODS),
            'people' => self::choices(CustomerPaymentResource::getEloquentQuery()->reorder()->whereNotNull('received_by_name')->distinct()->orderBy('received_by_name')
                ->pluck('received_by_name', 'received_by_name')->all()),
            'per' => self::PER_PAGE];
    }

    /** What each customer owes, by how late, the largest first (as the current page). */
    public function aging(): array
    {
        $aging = app(CustomerLedger::class)->aging();
        $names = Customer::whereIn('id', array_keys($aging))->get(['id', 'code', 'name', 'phone'])->keyBy('id');
        $open = CustomersScreen::canView();
        $rows = collect($aging)->map(fn ($b, $id) => ['id' => $id, 'name' => $names[$id]->name ?? '?', 'code' => trim(($names[$id]->code ?? '').' '.($names[$id]->phone ?? '')),
            'open' => $open ? "/office/customers/{$id}" : null,
            'current' => (int) $b['current'], 'd1_30' => (int) $b['d1_30'], 'd31_60' => (int) $b['d31_60'], 'd61_90' => (int) $b['d61_90'],
            'd90_plus' => (int) $b['d90_plus'], 'total' => (int) $b['total']])->sortByDesc('total')->values();
        $cur = $this->currency();

        return ['currency' => $cur, 'exponent' => Money::exponent($cur), 'rows' => $rows->all(),
            'totals' => collect(['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus', 'total'])->mapWithKeys(fn ($k) => [$k => (int) $rows->sum($k)])->all(), 'pay' => null];
    }

    /** @param  array<string, string>  $map */
    public static function choices(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private function currency(): string
    {
        return $this->user->tenant?->currency_code ?? 'GHS';
    }
}
