<?php

namespace App\Http\Controllers\Api\Public;

use App\Domain\Api\ApiClients;
use App\Domain\Api\OpenApi;
use App\Domain\Customers\CustomerService;
use App\Domain\Sales\CustomerOrderService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\ProductUom;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WebhookEndpoint;
use App\Security\Authorizer;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public REST API v1 (docs: /api/public/v1/openapi.json). Lists are pages of up to 200, oldest first by id, continued with
 * ?after=<last id>; ?updated_since= (ISO 8601) returns only what changed. Money in minor units with the currency code.
 */
class PublicApiController extends Controller
{
    public function __construct(private readonly Authorizer $authz) {}

    public function openapi(): JsonResponse
    {
        return response()->json(OpenApi::document());
    }

    public function products(Request $r): JsonResponse
    {
        $list = DB::table('price_lists')->where('tenant_id', $this->client($r)->tenant_id)->where('is_default', true)->first(['id', 'currency_code']);
        $q = DB::table('products as p')->where('p.tenant_id', $this->client($r)->tenant_id)
            ->when($r->filled('search'), fn ($w) => $w->where(fn ($x) => $x->where('p.name', 'ilike', '%'.$r->query('search').'%')->orWhere('p.sku', 'ilike', '%'.$r->query('search').'%')))
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->select(['p.id', 'p.sku', 'p.name', 'p.description', 'c.name as category', 'p.is_active', 'p.track_stock', 'p.updated_at']);

        return $this->page($r, $q, 'p', function ($rows) use ($list) {
            $ids = $rows->pluck('id');
            $units = DB::table('product_uoms as u')->join('uoms', 'uoms.id', '=', 'u.uom_id')->whereIn('u.product_id', $ids)->get(['u.id', 'u.product_id', 'uoms.code', 'u.factor', 'u.is_base'])->groupBy('product_id');
            $barcodes = DB::table('barcodes')->whereIn('product_uom_id', $units->flatten()->pluck('id'))->get(['product_uom_id', 'code'])->groupBy('product_uom_id');
            $prices = $list ? DB::table('prices')->where('price_list_id', $list->id)->whereIn('product_id', $ids)->where('min_qty', '<=', 1)
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->get(['product_uom_id', 'amount_minor'])->pluck('amount_minor', 'product_uom_id') : collect();

            return $rows->map(fn ($p) => ['id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'description' => $p->description, 'category' => $p->category,
                'active' => (bool) $p->is_active, 'track_stock' => (bool) $p->track_stock, 'updated_at' => $p->updated_at,
                'units' => ($units[$p->id] ?? collect())->map(fn ($u) => ['code' => $u->code, 'factor' => (float) $u->factor, 'base' => (bool) $u->is_base,
                    'barcodes' => ($barcodes[$u->id] ?? collect())->pluck('code')->all(), 'price_minor' => isset($prices[$u->id]) ? (int) $prices[$u->id] : null])->values()->all(),
                'currency' => $list?->currency_code]);
        });
    }

    public function product(Request $r, string $id): JsonResponse
    {
        $r->merge(['ids' => [$id]]);
        $data = $this->products($r)->getData(true)['data'];

        return $data ? response()->json(['data' => $data[0]]) : $this->notFound();
    }

    public function stock(Request $r): JsonResponse
    {
        $branches = $this->branches($r, 'stock.view');
        $q = DB::table('stock_levels as l')->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('l.tenant_id', $this->client($r)->tenant_id)->when($branches !== null, fn ($w) => $w->whereIn('w.branch_id', $branches))
            ->when($r->filled('warehouse'), fn ($w) => $w->where('w.code', $r->query('warehouse')))
            ->when($r->filled('product_id'), fn ($w) => $w->where('l.product_id', $r->query('product_id')))
            ->select(['l.id', 'w.code as warehouse', 'l.product_id', 'p.sku', 'l.on_hand', 'l.updated_at']);

        return $this->page($r, $q, 'l', fn ($rows) => $rows->map(fn ($l) => ['id' => $l->id, 'warehouse' => $l->warehouse, 'product_id' => $l->product_id, 'sku' => $l->sku,
            'on_hand' => (float) $l->on_hand, 'updated_at' => $l->updated_at]));
    }

    public function customers(Request $r): JsonResponse
    {
        $q = DB::table('customers as c')->where('c.tenant_id', $this->client($r)->tenant_id)->leftJoin('customer_balances as b', 'b.customer_id', '=', 'c.id')
            ->when($r->filled('search'), fn ($w) => $w->where(fn ($x) => $x->where('c.name', 'ilike', '%'.$r->query('search').'%')->orWhere('c.phone', 'like', '%'.$r->query('search').'%')->orWhere('c.code', $r->query('search'))))
            ->select(['c.id', 'c.code', 'c.name', 'c.phone', 'c.email', 'c.tax_no', 'c.credit_limit_minor', 'c.is_active', 'b.balance_minor', 'c.updated_at']);

        return $this->page($r, $q, 'c', fn ($rows) => $rows->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'phone' => $c->phone, 'email' => $c->email,
            'tax_no' => $c->tax_no, 'credit_limit_minor' => (int) $c->credit_limit_minor, 'balance_minor' => (int) ($c->balance_minor ?? 0), 'active' => (bool) $c->is_active, 'updated_at' => $c->updated_at]));
    }

    public function customer(Request $r, string $id): JsonResponse
    {
        $r->merge(['ids' => [$id]]);
        $data = $this->customers($r)->getData(true)['data'];

        return $data ? response()->json(['data' => $data[0]]) : $this->notFound();
    }

    public function createCustomer(Request $r): JsonResponse
    {
        return $this->run(function () use ($r) {
            $c = app(CustomerService::class)->save($this->actor($r), new Customer, array_intersect_key($r->json()->all(), array_flip(['name', 'phone', 'email', 'tax_no', 'address', 'type', 'contact_name'])), 'API: '.$this->client($r)->name);

            return response()->json(['data' => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name]], 201);
        });
    }

    public function sales(Request $r): JsonResponse
    {
        $branches = $this->branches($r, 'sales.view');
        $q = DB::table('sales as s')->join('branches as b', 'b.id', '=', 's.branch_id')->where('s.tenant_id', $this->client($r)->tenant_id)->where('s.status', 'posted')
            ->when($branches !== null, fn ($w) => $w->whereIn('s.branch_id', $branches))
            ->when($r->filled('from'), fn ($w) => $w->where('s.occurred_at', '>=', $r->query('from')))->when($r->filled('to'), fn ($w) => $w->where('s.occurred_at', '<', $r->query('to')))
            ->select(['s.id', 's.receipt_no', 's.kind', 'b.code as branch', 's.customer_id', 's.currency_code', 's.net_minor', 's.tax_minor', 's.total_minor', 's.occurred_at', 's.received_at as updated_at']);

        return $this->page($r, $q, 's', function ($rows) {
            $lines = DB::table('sale_lines')->whereIn('sale_id', $rows->pluck('id'))->orderBy('line_no')->get(['sale_id', 'product_id', 'sku', 'name', 'qty', 'uom_code', 'net_minor', 'tax_minor', 'line_total_minor'])->groupBy('sale_id');

            return $rows->map(fn ($s) => (array) $s + ['lines' => ($lines[$s->id] ?? collect())->map(fn ($l) => ['product_id' => $l->product_id, 'sku' => $l->sku, 'name' => $l->name,
                'qty' => (float) $l->qty, 'unit' => $l->uom_code, 'net_minor' => (int) $l->net_minor, 'tax_minor' => (int) $l->tax_minor, 'total_minor' => (int) $l->line_total_minor])->values()->all()]);
        }, 'received_at');
    }

    public function invoices(Request $r): JsonResponse
    {
        $branches = $this->branches($r, 'receivables.view');
        $q = DB::table('invoices as i')->where('i.tenant_id', $this->client($r)->tenant_id)->when($branches !== null, fn ($w) => $w->whereIn('i.branch_id', $branches))
            ->when($r->filled('customer_id'), fn ($w) => $w->where('i.customer_id', $r->query('customer_id')))
            ->select(['i.id', 'i.number', 'i.status', 'i.customer_id', 'i.customer_name', 'i.issued_on', 'i.due_on', 'i.currency_code', 'i.net_minor', 'i.tax_minor', 'i.total_minor', 'i.updated_at']);

        return $this->page($r, $q, 'i', fn ($rows) => $rows->map(fn ($i) => (array) $i));
    }

    public function invoice(Request $r, string $id): JsonResponse
    {
        $r->merge(['ids' => [$id]]);
        $data = $this->invoices($r)->getData(true)['data'];
        if (! $data) {
            return $this->notFound();
        }
        $data[0]['lines'] = DB::table('invoice_lines')->where('invoice_id', $id)->orderBy('line_no')->get()->map(fn ($l) => array_intersect_key((array) $l,
            array_flip(['line_no', 'product_id', 'sku', 'name', 'qty', 'uom_code', 'unit_price_minor', 'net_minor', 'tax_minor', 'total_minor'])))->all();

        return response()->json(['data' => $data[0]]);
    }

    /** Create a customer order (quotation, then confirmed unless "confirm": false). Lines by SKU and unit code (default the base unit). */
    public function createOrder(Request $r): JsonResponse
    {
        return $this->run(function () use ($r) {
            $in = $r->json()->all();
            $warehouse = isset($in['warehouse']) ? Warehouse::where('code', $in['warehouse'])->first() : Warehouse::where('branch_id', $this->client($r)->branch_id ?? DB::table('branches')->where('is_hq', true)->value('id'))->orderBy('code')->first();
            if (! $warehouse || ($this->client($r)->branch_id && $warehouse->branch_id !== $this->client($r)->branch_id)) {
                throw new DomainException('Unknown or not allowed warehouse.');
            }
            $lines = [];
            foreach ((array) ($in['lines'] ?? []) as $l) {
                $unit = ProductUom::join('products', 'products.id', '=', 'product_uoms.product_id')->join('uoms', 'uoms.id', '=', 'product_uoms.uom_id')
                    ->where('products.sku', $l['sku'] ?? '')->when(! empty($l['unit']), fn ($q) => $q->where('uoms.code', $l['unit']), fn ($q) => $q->where('product_uoms.is_base', true))
                    ->select('product_uoms.*')->first() ?? throw new DomainException('Unknown SKU or unit: '.($l['sku'] ?? '?'));
                $lines[] = ['product_uom_id' => $unit->id, 'qty' => (string) ($l['qty'] ?? '0')];
            }
            $svc = app(CustomerOrderService::class);
            $order = $svc->saveQuotation($this->actor($r), new CustomerOrder, ['customer_id' => $in['customer_id'] ?? null, 'warehouse_id' => $warehouse->id, 'lines' => $lines,
                'customer_ref' => $in['reference'] ?? null, 'note' => trim('Via API ('.$this->client($r)->name.'). '.($in['note'] ?? ''))]);
            if (($in['confirm'] ?? true) !== false) {
                $order = $svc->confirm($this->actor($r), $order);
            }

            return response()->json(['data' => ['id' => $order->id, 'number' => $order->number ?? $order->quote_number, 'status' => $order->status, 'total_minor' => (int) $order->total_minor]], 201);
        });
    }

    public function webhooks(Request $r): JsonResponse
    {
        return response()->json(['data' => WebhookEndpoint::where('api_client_id', $this->client($r)->id)->get(['id', 'url', 'events', 'is_active', 'failures'])]);
    }

    public function createWebhook(Request $r): JsonResponse
    {
        return $this->run(function () use ($r) {
            [$endpoint, $secret] = app(ApiClients::class)->addWebhook(null, $this->client($r)->tenant_id, $this->client($r), (string) $r->json('url'), (array) $r->json('events'));

            return response()->json(['data' => ['id' => $endpoint->id, 'url' => $endpoint->url, 'events' => $endpoint->events, 'secret' => $secret]], 201);
        });
    }

    public function deleteWebhook(Request $r, string $id): JsonResponse
    {
        $endpoint = WebhookEndpoint::where('api_client_id', $this->client($r)->id)->find($id);
        if (! $endpoint) {
            return $this->notFound();
        }
        $endpoint->delete();

        return response()->json([], 204);
    }

    private function client(Request $r): ApiClient
    {
        return $r->user();
    }

    private function actor(Request $r): Actor
    {
        /** @var User $user */
        $user = $r->attributes->get('api_user');

        return new Actor(id: $user->id, name: "{$user->name} (API: {$this->client($r)->name})", source: 'api', tenantId: $user->tenant_id, ip: $r->ip(), user: $user);
    }

    /** Branches the client may see for this permission: the run-as person's, narrowed to the client's branch. */
    private function branches(Request $r, string $permission): ?array
    {
        $ids = $this->authz->branchIds($r->attributes->get('api_user'), $permission);
        $own = $this->client($r)->branch_id;
        if ($own) {
            return $ids === null || in_array($own, $ids, true) ? [$own] : [];
        }

        return $ids;
    }

    private function page(Request $r, Builder $q, string $alias, \Closure $map, string $updatedColumn = 'updated_at'): JsonResponse
    {
        $limit = max(1, min(200, (int) $r->query('limit', 50)));
        $q->when($r->has('ids'), fn ($w) => $w->whereIn("{$alias}.id", (array) $r->input('ids')))
            ->when($r->filled('after'), fn ($w) => $w->where("{$alias}.id", '>', (string) $r->query('after')))
            ->when($r->filled('updated_since'), fn ($w) => $w->where("{$alias}.{$updatedColumn}", '>=', $r->query('updated_since')))
            ->orderBy("{$alias}.id")->limit($limit + 1);
        $rows = $q->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return response()->json(['data' => collect($map($rows))->values(), 'next' => $more ? $rows->last()->id : null]);
    }

    private function run(\Closure $call): JsonResponse
    {
        try {
            return $call();
        } catch (AccessDenied) {
            return response()->json(['error' => 'forbidden', 'detail' => 'The person this key acts as may not do that here.'], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['error' => 'not_found'], 404);
    }
}
