<?php

namespace App\Domain\Office;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Customer invoices in the new back office (release 4 of the rewrite): the list with the current screen's search
 * (number, customer, their reference), filters (status, past due, total, issued between) and columns, and one invoice
 * on a page of its own with Print / PDF and Credit note. Same query (InvoiceResource: the branches the person may see),
 * same rule: an invoice never changes; a credit note (ReceivablesService::creditNote) corrects it.
 */
final class InvoicesScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const SORTS = ['issued_on', 'due_on', 'number', 'total_minor', 'open_minor'];

    /** What is still owed on each invoice: its ledger entry less what was matched to it (as CustomerLedger::openItems). */
    private const OPEN = <<<'SQL'
        COALESCE((SELECT l.amount_minor - COALESCE((SELECT SUM(a.amount_minor) FROM receivable_allocations a WHERE a.to_type = 'invoice' AND a.to_id = invoices.id), 0)
            FROM customer_ledger l WHERE l.doc_type = 'invoice' AND l.doc_id = invoices.id AND l.type = 'invoice' LIMIT 1), 0)
        SQL;

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return InvoiceResource::canViewAny();
    }

    private function currency(): string
    {
        return $this->user->tenant?->currency_code ?? 'GHS';
    }

    /** The invoices the search and filters pick (also for the export). */
    public function filtered(array $p): Builder
    {
        $q = InvoiceResource::getEloquentQuery()->select('invoices.*')->selectRaw(self::OPEN.' AS open_minor');
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn (Builder $w) => $w->where('number', 'ilike', "%{$search}%")->orWhere('customer_name', 'ilike', "%{$search}%")
                ->orWhere('customer_ref', 'ilike', "%{$search}%")->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'ilike', "%{$search}%")));
        }
        if (array_key_exists($p['status'] ?? '', Invoice::STATUSES)) {
            $q->where('status', $p['status']);
        }
        $tz = $this->user->tenant?->timezone ?: 'UTC';
        $today = CarbonImmutable::now($tz)->toDateString();
        if (($p['overdue'] ?? '') === '1') { // as the current "Past due date" filter
            $q->where('status', 'posted')->whereDate('due_on', '<', $today);
        }
        if (($p['owed'] ?? '') === '1') {
            $q->whereRaw(self::OPEN.' > 0');
        }
        $period = (string) ($p['period'] ?? '');
        if ($period === 'today') {
            $q->whereDate('issued_on', $today);
        } elseif (in_array($period, ['7', '30', '90'], true)) {
            $q->whereDate('issued_on', '>=', CarbonImmutable::now($tz)->subDays((int) $period - 1)->toDateString());
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            if (is_string($p[$key] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $p[$key])) {
                $q->whereDate('issued_on', $op, $p[$key]);
            }
        }
        if (is_numeric($p['min'] ?? null)) {
            $q->where('total_minor', '>=', Money::toMinor((string) $p['min'], $this->currency()));
        }
        if (is_numeric($p['max'] ?? null)) {
            $q->where('total_minor', '<=', Money::toMinor((string) $p['max'], $this->currency()));
        }

        return $q;
    }

    /** The order the list shows: [column, direction]. */
    public function order(array $p): array
    {
        return [in_array($p['sort'] ?? null, self::SORTS, true) ? $p['sort'] : 'issued_on', ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc'];
    }

    public function sorted(array $p): Builder
    {
        [$sort, $dir] = $this->order($p);
        $q = $this->filtered($p);

        return ($sort === 'open_minor' ? $q->orderByRaw(self::OPEN." {$dir}") : $q->orderBy($sort, $dir))->orderBy('number', $dir);
    }

    /** One row of the list. */
    public function rowOf(Invoice $i): array
    {
        $open = (int) ($i->open_minor ?? 0);

        return [
            'id' => $i->id, 'number' => $i->number, 'customer' => $i->customer_name, 'ref' => $i->customer_ref,
            'issued_on' => $i->issued_on?->toDateString(), 'due_on' => $i->due_on?->toDateString(), 'currency' => $i->currency_code,
            'total' => (int) $i->total_minor, 'open' => $open, 'status' => $i->status,
            'overdue' => $open > 0 && $i->due_on !== null && $i->due_on->lt(CarbonImmutable::now($this->user->tenant?->timezone ?: 'UTC')->startOfDay()),
        ];
    }

    /** @param  array<string, mixed>  $p  q, status, overdue, owed, period, from, to, min, max, sort, dir, page, per */
    public function list(array $p): array
    {
        $base = $this->filtered($p);
        $t = DB::query()->fromSub($base, 'x')->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_minor), 0) AS total, COALESCE(SUM(open_minor), 0) AS open,
            COALESCE(SUM(CASE WHEN open_minor > 0 AND due_on < ? THEN open_minor ELSE 0 END), 0) AS overdue',
            [CarbonImmutable::now($this->user->tenant?->timezone ?: 'UTC')->toDateString()])->first();
        [$sort, $dir] = $this->order($p);
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $currency = $this->currency();

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency),
            'total' => (int) $t->n, 'sum' => (int) $t->total, 'open' => (int) $t->open, 'overdue' => (int) $t->overdue,
            'page' => $page, 'per' => $per, 'sort' => $sort, 'dir' => $dir,
            'rows' => $this->sorted($p)->forPage($page, $per)->get()->map(fn (Invoice $i) => $this->rowOf($i))->all(),
        ];
    }

    public function options(): array
    {
        return ['statuses' => self::choices(Invoice::STATUSES), 'per' => self::PER_PAGE];
    }

    /** One invoice: what the current page shows, what settled it, and Print / Credit note. */
    public function show(Invoice $i): array
    {
        $i->loadMissing(['lines', 'customer', 'branch']);
        $cur = $i->currency_code;
        $open = (int) ($this->filtered([])->whereKey($i->id)->value('open_minor') ?? 0);
        $today = CarbonImmutable::now($this->user->tenant?->timezone ?: 'UTC')->startOfDay();
        $settled = DB::table('receivable_allocations as a')
            ->leftJoin('customer_ledger as l', fn ($j) => $j->on('l.doc_type', '=', 'a.from_type')->on('l.doc_id', '=', 'a.from_id')->whereIn('l.type', ['payment', 'credit_note', 'pos_return']))
            ->where('a.to_type', 'invoice')->where('a.to_id', $i->id)->orderBy('a.created_at')
            ->get(['a.from_type', 'a.amount_minor', 'a.created_at', 'l.type', 'l.doc_number', 'l.occurred_at'])
            ->map(fn ($r) => ['kind' => ['payment' => 'Payment', 'credit_note' => 'Credit note', 'pos_return' => 'Till return'][$r->type ?? ''] ?? $r->from_type,
                'number' => $r->doc_number, 'on' => CarbonImmutable::parse($r->occurred_at ?? $r->created_at)->toDateString(), 'amount' => (int) $r->amount_minor])->all();
        $mayCredit = $i->status === 'posted' && Erp::can('receivables.credit_note', $i->branch);

        return [
            'currency' => $cur, 'exponent' => Money::exponent($cur),
            'id' => $i->id, 'number' => $i->number, 'status' => $i->status, 'status_label' => Invoice::STATUSES[$i->status] ?? $i->status,
            'customer' => ['id' => $i->customer_id, 'name' => $i->customer_name, 'tax_no' => $i->customer_tax_no,
                'open' => CustomerResource::canViewAny() ? "/office/customers/{$i->customer_id}" : null],
            'ref' => $i->customer_ref, 'branch' => $i->branch ? "{$i->branch->code} · {$i->branch->name}" : null,
            'issued_on' => $i->issued_on?->toDateString(), 'due_on' => $i->due_on?->toDateString(),
            'overdue' => $open > 0 && $i->due_on?->lt($today), 'prices_include_tax' => (bool) $i->prices_include_tax,
            'net' => (int) $i->net_minor, 'tax' => (int) $i->tax_minor, 'total' => (int) $i->total_minor, 'open' => $open,
            'tax_breakdown' => collect($i->tax_breakdown ?? [])->map(fn ($t) => ['name' => (string) ($t['name'] ?? ''), 'amount' => (int) ($t['amount_minor'] ?? 0)])->all(),
            'lines' => $i->lines->map(fn ($l) => ['sku' => $l->sku, 'name' => $l->name, 'qty' => Fields::qty($l->qty, $l->uom_code),
                'unit_price' => (int) $l->unit_price_minor, 'total' => (int) $l->total_minor])->all(),
            'settled' => $settled,
            'credit_notes' => CreditNote::where('invoice_id', $i->id)->orderBy('created_at')->get()->map(fn (CreditNote $n) => [
                'number' => $n->number, 'on' => $n->created_at?->toDateString(), 'reason' => $n->reason, 'goods_returned' => (bool) $n->goods_returned,
                'by' => $n->approved_by_name, 'total' => (int) $n->total_minor])->all(),
            'actions' => [
                'print' => route('print.invoice', $i->id, false),
                'credit' => $mayCredit ? ['warehouses' => self::choices(Fields::warehouses('sales.deliver'))] : null,
            ],
        ];
    }

    /** @return list<array{id: string, label: string}> */
    private static function choices(array $map): array
    {
        return collect($map)->map(fn ($label, $id) => ['id' => (string) $id, 'label' => (string) $label])->values()->all();
    }
}
