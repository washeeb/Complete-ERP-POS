<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Customers\CustomerLedger;
use App\Domain\Customers\CustomerService;
use App\Domain\Devices\DeviceSignature;
use App\Domain\Pos\PosSyncService;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Support\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Device;
use App\Models\LegalEntity;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\StockLevel;
use App\Models\Tenant;
use App\Models\Till;
use App\Security\Authorizer;
use App\Support\Money;
use App\Support\Qty;
use App\Support\Settings;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Endpoints a signed-in till uses. Device + staff session are checked by the device.token:pos middleware. */
class PosController extends Controller
{
    /** Everything the till needs to start: who, where, which lists, receipt header/footer, open shift. */
    public function bootstrap(Request $request, ShiftService $shifts, Settings $settings): JsonResponse
    {
        $device = $this->device($request);
        $till = $device->till_id ? Till::with('warehouse')->find($device->till_id) : null;
        $branch = Branch::with('legalEntity')->find($device->branch_id);
        $tenant = Tenant::find($device->tenant_id);
        $entity = $branch->legalEntity ?? LegalEntity::first();

        return response()->json([
            'company' => ['name' => $tenant->name, 'currency' => $tenant->currency_code, 'exponent' => Money::exponent($tenant->currency_code),
                'timezone' => $branch->timezone ?? $tenant->timezone, 'legal_name' => $entity?->name, 'tin' => $entity?->tin, 'vat_no' => $entity?->vat_no],
            'branch' => ['id' => $branch->id, 'code' => $branch->code, 'name' => $branch->name, 'address' => $branch->address, 'phone' => $branch->phone],
            'till' => $till ? ['id' => $till->id, 'code' => $till->code, 'name' => $till->name, 'warehouse' => $till->warehouse->name] : null,
            'device' => ['id' => $device->id, 'name' => $device->name, 'receipt_series' => $device->receipt_series],
            'user' => ['id' => $request->user()->id, 'name' => $request->user()->name],
            'price_lists' => $this->lists($branch)->map(fn (PriceList $l) => [
                'id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'prices_include_tax' => $l->prices_include_tax, 'is_default' => $l->is_default,
            ])->values(),
            'receipt_footer' => $settings->get('receipt.footer', $device->tenant_id),
            'shift' => $this->shiftJson($shifts->current($device), $shifts),
            'last_device_seq' => (int) Sale::where('device_id', $device->id)->max('device_seq'),
            'catalog_version' => $this->catalogVersion(),
            // Live updates (Reverb). null = not configured on this server; the till then polls.
            'realtime' => config('broadcasting.default') === 'reverb' && config('broadcasting.connections.reverb.key') ? [
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => config('broadcasting.connections.reverb.options.host'),
                'port' => (int) config('broadcasting.connections.reverb.options.port'),
                'scheme' => config('broadcasting.connections.reverb.options.scheme'),
                'channels' => ['tenant.'.$device->tenant_id.'.catalog', $till ? 'tenant.'.$device->tenant_id.'.stock.'.$till->warehouse_id : null],
            ] : null,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /** Sellable catalog for this till: units, barcodes, tax components, prices on usable lists, stock at the till's warehouse. */
    public function catalog(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $version = $this->catalogVersion();
        // The till caches the catalog for offline use and asks only when something changed.
        if ($request->header('If-None-Match') === '"'.$version.'"') {
            return response()->json(null, 304)->setEtag($version);
        }
        $till = Till::find($device->till_id);
        $lists = $this->lists(Branch::find($device->branch_id))->pluck('id');
        $now = now();

        $products = Product::where('is_active', true)
            ->with(['taxGroup.rates' => fn ($q) => $q->where('is_active', true), 'units' => fn ($q) => $q->where('is_sellable', true),
                'units.uom', 'units.barcodes',
                'prices' => fn ($q) => $q->whereIn('price_list_id', $lists)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))])
            ->orderBy('name')->limit(20000)->get();
        $stock = $till ? StockLevel::where('warehouse_id', $till->warehouse_id)->pluck('on_hand', 'product_id') : collect();

        return response()->json([
            'version' => $version,
            'generated_at' => $now->toIso8601String(),
            'products' => $products->map(fn (Product $p) => [
                'id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'track_stock' => $p->track_stock,
                'on_hand' => $p->track_stock ? (string) ($stock[$p->id] ?? '0.000') : null,
                'tax' => $p->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values(),
                'units' => $p->units->map(fn ($u) => [
                    'id' => $u->id, 'uom_code' => $u->uom->code, 'uom_name' => $u->uom->name, 'decimals' => $u->uom->decimals,
                    'factor' => (string) $u->factor, 'is_base' => $u->is_base, 'barcodes' => $u->barcodes->pluck('code'),
                ])->values(),
                'prices' => $p->prices->map(fn ($x) => [
                    'price_list_id' => $x->price_list_id, 'product_uom_id' => $x->product_uom_id, 'min_qty' => (string) $x->min_qty,
                    'amount_minor' => $x->amount_minor, 'starts_at' => $x->starts_at?->toIso8601String(), 'ends_at' => $x->ends_at?->toIso8601String(),
                ])->values(),
            ])->values(),
        ])->setEtag($version);
    }

    /** Changes whenever anything the till caches changes (catalog, prices, tax, lists, stock). */
    /**
     * Customers for lookup at the till (cached for offline use; ETag). Credit figures are a snapshot: the server
     * re-checks every credit sale it receives online.
     */
    public function customers(Request $request, CustomerService $credit, CustomerLedger $ledger, Authorizer $authz): JsonResponse
    {
        if (! $authz->allowsAnywhere($request->user(), 'customers.view')) {
            return response()->json(['version' => 'none', 'customers' => []]);
        }
        $tenant = app(TenantContext::class)->id();
        $version = md5(implode('|', [
            DB::table('customers')->where('tenant_id', $tenant)->count(), DB::table('customers')->where('tenant_id', $tenant)->max('updated_at'),
            DB::table('customer_balances')->where('tenant_id', $tenant)->max('updated_at'),
            DB::table('customer_orders')->where('tenant_id', $tenant)->max('updated_at'),
            now()->toDateString(), // overdue status changes with the date
        ]));
        if ($request->header('If-None-Match') === '"'.$version.'"') {
            return response()->json(null, 304)->setEtag($version);
        }

        return response()->json(['version' => $version, 'customers' => Customer::where('is_active', true)->with('balance')->orderBy('name')->limit(50000)->get()
            ->map(fn (Customer $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'phone' => $c->phone, 'price_list_id' => $c->price_list_id,
                'credit_limit_minor' => $c->credit_limit_minor, 'credit_hold' => $c->credit_hold, 'balance_minor' => $c->balanceMinor(),
                'available_minor' => $c->credit_limit_minor === null ? null : $credit->availableCredit($c),
                'overdue_minor' => $c->credit_limit_minor === null && $c->balanceMinor() <= 0 ? 0 : $ledger->overdueMinor($c->id)])->values()])->setEtag($version);
    }

    private function catalogVersion(): string
    {
        $tenant = app(TenantContext::class)->id();
        $parts = [];
        foreach (['products', 'product_uoms', 'barcodes', 'prices', 'price_lists', 'tax_rates', 'tax_groups', 'uoms'] as $t) {
            $row = DB::table($t)->where('tenant_id', $tenant)->selectRaw('count(*) as c, max(updated_at) as u')->first();
            $parts[] = $t.':'.$row->c.':'.$row->u;
        }
        $parts[] = 'tax_group_rates:'.DB::table('tax_group_rates')->count();
        $parts[] = 'stock:'.DB::table('stock_levels')->where('tenant_id', $tenant)->max('updated_at');

        return substr(hash('sha256', implode('|', $parts)), 0, 32);
    }

    public function openShift(Request $request, ShiftService $shifts): JsonResponse
    {
        $data = $request->validate([
            'opening_float_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'id' => ['nullable', 'uuid'], // till-generated, so the same shift can later be referenced offline
        ]);
        $shift = $shifts->open($this->device($request), $request->user(), (int) $data['opening_float_minor'], $data['id'] ?? null);

        return response()->json(['shift' => $this->shiftJson($shift, $shifts)], 201);
    }

    public function closeShift(Request $request, ShiftService $shifts): JsonResponse
    {
        $data = $request->validate([
            'counted_cash_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $shift = $shifts->close($this->device($request), $request->user(), (int) $data['counted_cash_minor'], $data['note'] ?? null);

        return response()->json(['shift' => $this->shiftJson($shift, $shifts, reveal: true)]);
    }

    public function postSale(Request $request, SaleService $sales): JsonResponse
    {
        $request->validate([
            'id' => ['required', 'uuid'],
            'device_seq' => ['required', 'integer', 'min:1'],
            'occurred_at' => ['required', 'date'],
            'price_list_id' => ['required', 'uuid'],
            'shift_id' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.product_uom_id' => ['required', 'uuid'],
            'lines.*.qty' => ['required', 'string', 'max:20'],
            'lines.*.unit_price_minor' => ['required', 'integer', 'min:0'],
            'lines.*.line_total_minor' => ['required', 'integer', 'min:0'],
            'totals.net_minor' => ['required', 'integer'],
            'totals.tax_minor' => ['required', 'integer'],
            'totals.total_minor' => ['required', 'integer'],
            'payments' => ['required', 'array', 'min:1', 'max:10'],
            'payments.*.method' => ['required', 'string', 'max:20'],
            'payments.*.provider' => ['nullable', 'string', 'max:20'],
            'payments.*.amount_minor' => ['required', 'integer', 'min:1'],
            'payments.*.tendered_minor' => ['nullable', 'integer', 'min:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:80'],
            'payments.*.payer_phone' => ['nullable', 'string', 'max:20'],
        ]);
        $device = $this->device($request);
        $result = $sales->post($device, $request->user(), $request->all());

        return response()->json(['sale' => $this->saleJson($result['sale']), 'replayed' => $result['replayed']], $result['replayed'] ? 200 : 201);
    }

    /**
     * Queued work from a till that was offline (or still has a queue). Authenticated by the DEVICE
     * token (the people who sold may have signed out) and signed by the device's own key; each
     * event carries the offline grant of the person who did it.
     */
    public function sync(Request $request, PosSyncService $sync, DeviceSignature $signature): JsonResponse
    {
        $device = $this->device($request);
        $signature->verify($device, $request->getContent(), $request->header('X-Device-Signature'));
        $data = $request->validate(['events' => ['required', 'array', 'min:1', 'max:200']]);
        $device->forceFill(['last_seen_at' => now()])->saveQuietly();

        return response()->json(['results' => $sync->apply($device, $data['events'])]);
    }

    public function showSale(Request $request, string $id): JsonResponse
    {
        $sale = Sale::where('device_id', $this->device($request)->id)->with('lines', 'payments')->find($id)
            ?? throw new DomainException('Sale not found on this device.', 'not_found');

        return response()->json(['sale' => $this->saleJson($sale)]);
    }

    // -------------------------------------------------------------------

    private function device(Request $request): Device
    {
        return $request->attributes->get('device');
    }

    private function lists(Branch $branch)
    {
        return PriceList::where('is_active', true)
            ->where(fn ($q) => $q->where('pos_selectable', true)->orWhere('is_default', true)
                ->orWhereIn('id', Customer::where('is_active', true)->whereNotNull('price_list_id')->select('price_list_id'))) // customers' own lists
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))
            ->orderByDesc('is_default')->orderBy('name')->get();
    }

    private function shiftJson(?Shift $shift, ShiftService $shifts, bool $reveal = false): ?array
    {
        if (! $shift) {
            return null;
        }

        return [
            'id' => $shift->id, 'status' => $shift->status, 'opened_at' => $shift->opened_at->toIso8601String(),
            'opened_by' => $shift->opened_by_name, 'opening_float_minor' => $shift->opening_float_minor,
            // Blind count: expected cash is only revealed after the count is submitted.
            'expected_cash_minor' => $reveal ? $shift->expected_cash_minor : null,
            'counted_cash_minor' => $reveal ? $shift->counted_cash_minor : null,
            'over_short_minor' => $reveal ? $shift->over_short_minor : null,
        ];
    }

    private function saleJson(Sale $s): array
    {
        return [
            'id' => $s->id, 'receipt_no' => $s->receipt_no, 'device_seq' => $s->device_seq, 'status' => $s->status,
            'occurred_at' => $s->occurred_at->toIso8601String(), 'cashier' => $s->cashier_name, 'currency' => $s->currency_code,
            'prices_include_tax' => $s->prices_include_tax, 'net_minor' => $s->net_minor, 'tax_minor' => $s->tax_minor,
            'total_minor' => $s->total_minor, 'paid_minor' => $s->paid_minor, 'change_minor' => $s->change_minor,
            'tax_breakdown' => $s->tax_breakdown,
            'lines' => $s->lines->map(fn ($l) => ['line_no' => $l->line_no, 'sku' => $l->sku, 'name' => $l->name, 'uom' => $l->uom_code,
                'qty' => Qty::format(Qty::toMilli((string) $l->qty)), 'unit_price_minor' => $l->unit_price_minor, 'line_total_minor' => $l->line_total_minor])->values(),
            'payments' => $s->payments->map(fn ($p) => ['method' => $p->method, 'provider' => $p->provider, 'amount_minor' => $p->amount_minor,
                'tendered_minor' => $p->tendered_minor, 'reference' => $p->reference, 'status' => $p->status])->values(),
        ];
    }
}
