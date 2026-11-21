<?php

namespace App\Domain\Office;

use App\Filament\Resources\CustomerOrders\CustomerOrderResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Waybills\WaybillResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Delivery;
use App\Models\ProductUom;
use App\Models\User;
use App\Models\Waybill;
use App\Support\IndustryContext;
use App\Support\Money;
use App\Support\Qty;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quotes & orders in the new back office (part 15 of the rewrite), as the current pages: the list (search by quote or
 * order number or customer; status, amount, entered by), one quote or order with every button of the current page
 * (Turn into order, Release credit hold, Deliver, Print, Waybill, Invoices, Mark lost / Cancel order) and the quotation
 * form (customer, store, valid until, reference, note, lines priced from the customer's price list when saved). Same
 * query (CustomerOrderResource: the branches the person may see), same rules: CustomerOrderService and DeliveryService
 * (CustomerOrdersController).
 */
final class CustomerOrdersScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const SORTS = ['created_at', 'total_minor', 'quote_number'];

    public const TONE = ['quotation' => 'warning', 'on_hold' => 'danger', 'confirmed' => 'informative', 'partially_delivered' => 'informative',
        'delivered' => 'success', 'lost' => 'subtle', 'cancelled' => 'subtle'];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return CustomerOrderResource::canViewAny();
    }

    public static function canCreate(): bool
    {
        return CustomerOrderResource::canCreate();
    }

    public function filtered(array $p): Builder
    {
        $q = CustomerOrderResource::getEloquentQuery();
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn (Builder $w) => $w->where('customer_orders.quote_number', 'ilike', "%{$search}%")->orWhere('customer_orders.number', 'ilike', "%{$search}%")
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'ilike', "%{$search}%")));
        }
        $statuses = array_values(array_intersect((array) ($p['status'] ?? []), array_keys(CustomerOrder::STATUSES)));
        if ($statuses) {
            $q->whereIn('customer_orders.status', $statuses);
        }
        $cur = $this->currency();
        foreach (['min' => '>=', 'max' => '<='] as $k => $op) {
            if (is_numeric($p[$k] ?? null)) {
                $q->where('customer_orders.total_minor', $op, Money::toMinor((string) $p[$k], $cur));
            }
        }
        if (filled($p['by'] ?? null)) {
            $q->where('customer_orders.created_by_name', (string) $p['by']);
        }

        return $q;
    }

    /** @return array{0: string, 1: string} */
    public function order(array $p): array
    {
        return [in_array($p['sort'] ?? null, self::SORTS, true) ? $p['sort'] : 'created_at', ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc'];
    }

    public function sorted(array $p): Builder
    {
        [$sort, $dir] = $this->order($p);

        return $this->filtered($p)->orderBy("customer_orders.{$sort}", $dir)->orderBy('customer_orders.id', $dir);
    }

    public function rowOf(CustomerOrder $o): array
    {
        return ['id' => $o->id, 'quote' => $o->quote_number, 'order' => $o->number, 'customer' => $o->customer?->name, 'currency' => $o->currency_code,
            'total' => (int) $o->total_minor, 'status' => $o->status, 'created_at' => $o->created_at?->toIso8601String(), 'by' => $o->created_by_name];
    }

    /** @param  array<string, mixed>  $p  q, status[], min, max, by, sort, dir, page, per */
    public function list(array $p): array
    {
        $t = (clone $this->filtered($p))->toBase()->reorder()->selectRaw("COUNT(*) AS n, COUNT(*) FILTER (WHERE status = 'quotation') AS quotes, "
            ."COUNT(*) FILTER (WHERE status = 'on_hold') AS held, COALESCE(SUM(total_minor) FILTER (WHERE status IN ('confirmed', 'partially_delivered')), 0) AS open")->first();
        [$sort, $dir] = $this->order($p);
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $cur = $this->currency();

        return ['currency' => $cur, 'exponent' => Money::exponent($cur), 'total' => (int) $t->n, 'quotes' => (int) $t->quotes, 'held' => (int) $t->held,
            'open' => (int) $t->open, 'page' => $page, 'per' => $per, 'sort' => $sort, 'dir' => $dir,
            'rows' => $this->sorted($p)->forPage($page, $per)->get()->map(fn (CustomerOrder $o) => $this->rowOf($o))->all()];
    }

    public function options(): array
    {
        return ['statuses' => self::choices(CustomerOrder::STATUSES),
            'people' => self::choices(CustomerOrderResource::getEloquentQuery()->reorder()->whereNotNull('created_by_name')->distinct()->orderBy('created_by_name')
                ->pluck('created_by_name', 'created_by_name')->all()),
            'per' => self::PER_PAGE, 'can_create' => self::canCreate()];
    }

    /** "SO-000012 (from QT-000034)", or the quote number while it is a quotation. */
    public static function label(CustomerOrder $o): string
    {
        return CustomerOrderResource::label($o);
    }

    /** One quote or order: what the current page shows, and what may be done with it now (with what each dialog needs). */
    public function show(CustomerOrder $o): array
    {
        $o->loadMissing(['lines', 'customer', 'warehouse']);
        $wh = $o->warehouseModel();
        $manage = Erp::can('sales.orders.manage', $wh);
        $delivery = Delivery::where('customer_order_id', $o->id)->latest()->first();
        $waybill = $delivery ? Waybill::where('source_id', $delivery->id)->where('status', '<>', 'cancelled')->first() : null;
        $left = fn ($l) => Qty::format(max(0, Qty::toMilli((string) $l->qty) - Qty::toMilli((string) $l->delivered_qty)));

        return [
            'currency' => $o->currency_code, 'exponent' => Money::exponent($o->currency_code),
            'id' => $o->id, 'label' => self::label($o), 'quote' => $o->quote_number, 'order' => $o->number, 'status' => $o->status,
            'status_label' => CustomerOrder::STATUSES[$o->status] ?? $o->status, 'tone' => self::TONE[$o->status] ?? 'informative',
            'customer' => ['name' => $o->customer?->name, 'open' => CustomersScreen::canView() ? "/office/customers/{$o->customer_id}" : null],
            'warehouse' => $wh ? "{$wh->code} · {$wh->name}" : null,
            'net' => (int) $o->net_minor, 'tax' => (int) $o->tax_minor, 'total' => (int) $o->total_minor,
            'customer_ref' => $o->customer_ref, 'valid_until' => $o->valid_until?->toDateString(), 'created_by' => $o->created_by_name,
            'created_at' => $o->created_at?->toIso8601String(), 'released_by' => $o->released_by_name,
            'hold_reason' => $o->status === 'on_hold' ? $o->hold_reason : null, 'close_reason' => $o->close_reason, 'note' => $o->note,
            'lines' => $o->lines->map(fn ($l) => ['sku' => $l->sku, 'name' => $l->name, 'ordered' => Fields::qty($l->qty, $l->uom_code),
                'delivered' => Fields::qty($l->delivered_qty, $l->uom_code), 'price' => (int) $l->unit_price_minor, 'total' => (int) $l->total_minor])->values()->all(),
            'actions' => [
                'print' => route('print.order', $o->id, false),
                'edit' => CustomerOrderResource::canEdit($o),
                'confirm' => $o->status === 'quotation' && $manage,
                'release' => $o->status === 'on_hold' && Erp::can('customers.credit.override', $wh) && $o->created_by !== $this->user->id, // never one's own order (the service refuses it)
                'deliver' => $o->isDeliverable() && Erp::can('sales.deliver', $wh)
                    ? ['lines' => $o->lines->map(fn ($l) => ['id' => $l->id, 'item' => "{$l->sku} · {$l->name} ({$l->uom_code})", 'ordered' => Fields::qty($l->qty),
                        'delivered' => Fields::qty($l->delivered_qty), 'qty' => Fields::qty($left($l))])->values()->all()] : null,
                'waybill' => $delivery ? ProductsScreen::path($waybill ? WaybillResource::getUrl('view', ['record' => $waybill])
                    : WaybillResource::getUrl('create', ['kind' => 'delivery', 'source' => $delivery->id])) : null,
                'invoices' => in_array($o->status, ['partially_delivered', 'delivered'], true) ? ProductsScreen::path(InvoiceResource::getUrl('index', ['tableSearch' => $o->number])) : null,
                'cancel' => in_array($o->status, ['quotation', 'on_hold', 'confirmed', 'partially_delivered'], true) && $manage
                    ? ($o->status === 'quotation' ? 'Mark lost' : 'Cancel order') : null,
            ],
        ];
    }

    /** The quotation form: the current form's fields. */
    public function form(?CustomerOrder $o): array
    {
        $o?->loadMissing(['lines', 'customer']);
        $units = $o ? ProductUom::with('product', 'uom')->whereIn('id', $o->lines->pluck('product_uom_id')->filter())->get()->keyBy('id') : collect();
        $warehouses = Fields::warehouses('sales.orders.manage');

        return [
            'id' => $o?->id, 'number' => $o?->quote_number,
            'values' => [
                'customer_id' => (string) ($o?->customer_id ?? ''), 'customer_label' => $o?->customer?->name,
                'warehouse_id' => (string) ($o?->warehouse_id ?? array_key_first($warehouses) ?? ''),
                'valid_until' => $o?->valid_until?->toDateString() ?? now()->addDays(14)->toDateString(), 'customer_ref' => (string) ($o?->customer_ref ?? ''),
                'note' => (string) ($o?->note ?? ''),
                'lines' => $o ? $o->lines->map(fn ($l) => ['product_uom_id' => (string) $l->product_uom_id, 'qty' => Fields::qty($l->qty),
                    'label' => ($u = $units->get($l->product_uom_id)) ? Fields::unitLabel($u) : "{$l->sku} · {$l->name} ({$l->uom_code})"])->values()->all() : [],
            ],
            'warehouses' => self::choices($warehouses),
            'can_add_customer' => Erp::canAnywhere('customers.manage'),
        ];
    }

    /** Active customers by name, code or phone (as the current form's customer field). */
    public function customers(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $digits = preg_replace('/\D/', '', $q);

        return Customer::where('is_active', true)->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('code', 'ilike', "%{$q}%")
            ->when($digits !== '', fn ($x) => $x->orWhere('phone', 'like', "%{$digits}%")))
            ->orderBy('name')->limit(30)->get(['id', 'name', 'code', 'phone'])
            ->map(fn (Customer $c) => ['id' => $c->id, 'label' => $c->name, 'detail' => trim("{$c->code} {$c->phone}")])->all();
    }

    /** Units of any active product (as the current form's "Product and unit"). */
    public function units(string $q): array
    {
        if (mb_strlen($q) < 2) {
            return [];
        }

        return ProductUom::with('product', 'uom')
            ->whereHas('product', fn (Builder $w) => IndustryContext::productScope($w)->whereNull('products.archived_at'))
            ->where(fn ($w) => $w->whereHas('product', fn (Builder $x) => $x->where('is_active', true)->where(fn ($y) => $y->where('name', 'ilike', "%{$q}%")
                ->orWhere('sku', 'ilike', "%{$q}%")))->orWhereHas('barcodes', fn ($b) => $b->where('code', $q)))
            ->limit(30)->get()->sortBy(fn ($u) => [$u->product->name, -(float) $u->factor])->values()
            ->map(fn (ProductUom $u) => ['id' => $u->id, 'product_id' => $u->product_id, 'label' => Fields::unitLabel($u), 'batches' => false, 'serialised' => false])->all();
    }

    /** What the store holds of a line's product, in the line's unit ("Available"). */
    public function available(string $unitId, string $warehouseId): ?string
    {
        $s = Fields::stockOf($unitId, $warehouseId);

        return $s ? Fields::qty((string) round($s['qty'], 3), $s['unit']) : null;
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
