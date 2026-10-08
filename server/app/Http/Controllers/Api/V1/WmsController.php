<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Devices\PosAuthService;
use App\Domain\Inventory\BinService;
use App\Domain\Inventory\StockCountService;
use App\Domain\Inventory\TransferService;
use App\Domain\Purchasing\GoodsReceiptService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Http\Controllers\Controller;
use App\Models\Barcode;
use App\Models\Bin;
use App\Models\Device;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\PurchaseOrder;
use App\Models\StockCount;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Security\Authorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The warehouse handheld (a PWA on a scanner phone): receive purchase orders, put away into bins, receive
 * transfers and count. Signed in by PIN on an enrolled handheld device; works against its branch's own server.
 * Every action goes through the same domain services as the back office.
 */
class WmsController extends Controller
{
    public function __construct(private readonly Authorizer $authz) {}

    private function actor(Request $request): Actor
    {
        return Actor::user($request->user(), 'handheld', $this->device($request)->id);
    }

    private function device(Request $request): Device
    {
        return $request->attributes->get('device');
    }

    /** A warehouse of the handheld's branch where the person holds the permission. */
    private function warehouse(Request $request, string $id, string $permission): Warehouse
    {
        $w = Warehouse::where('branch_id', $this->device($request)->branch_id)->findOrFail($id);
        if (! $this->authz->allows($request->user(), $permission, $w)) {
            throw new AccessDenied;
        }

        return $w;
    }

    public function bootstrap(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $user = $request->user();
        $warehouses = Warehouse::where('branch_id', $device->branch_id)->where('is_active', true)->orderBy('code')->get()
            ->filter(fn ($w) => collect(PosAuthService::HANDHELD_PERMISSIONS)->contains(fn ($p) => $this->authz->allows($user, $p, $w)));

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name],
            'device' => ['id' => $device->id, 'name' => $device->name, 'kind' => $device->kind],
            'branch' => ['id' => $device->branch_id, 'name' => $device->branch->name, 'code' => $device->branch->code],
            'warehouses' => $warehouses->map(fn ($w) => [
                'id' => $w->id, 'code' => $w->code, 'name' => $w->name, 'use_bins' => $w->use_bins,
                'can' => collect(PosAuthService::HANDHELD_PERMISSIONS)->filter(fn ($p) => $this->authz->allows($user, $p, $w))->values(),
                'bins' => $w->use_bins ? Bin::where('warehouse_id', $w->id)->where('is_active', true)->orderBy('pick_order')->orderBy('code')
                    ->get(['id', 'code', 'name', 'kind']) : [],
            ])->values(),
        ]);
    }

    /** Scan: a product barcode (or SKU), or a bin label. */
    public function lookup(Request $request): JsonResponse
    {
        $code = trim((string) $request->query('code', ''));
        $warehouseId = (string) $request->query('warehouse_id', '');
        if ($code === '') {
            return response()->json(['error' => 'not_found', 'detail' => 'Nothing scanned.'], 404);
        }
        if ($warehouseId && ($bin = Bin::where('warehouse_id', $warehouseId)->where('code', strtoupper($code))->first())) {
            return response()->json(['type' => 'bin', 'bin' => ['id' => $bin->id, 'code' => $bin->code, 'name' => $bin->name]]);
        }
        $unit = ($b = Barcode::where('code', $code)->first()) ? ProductUom::find($b->product_uom_id)
            : (($p = Product::where('sku', strtoupper($code))->first()) ? ProductUom::where('product_id', $p->id)->where('is_base', true)->first() : null);
        if (! $unit) {
            return response()->json(['error' => 'not_found', 'detail' => "Nothing found for “{$code}”."], 404);
        }

        return response()->json(['type' => 'product', 'unit' => $this->unit($unit)]);
    }

    private function unit(ProductUom $u): array
    {
        $u->loadMissing('product', 'uom');

        return ['product_uom_id' => $u->id, 'product_id' => $u->product_id, 'sku' => $u->product->sku, 'name' => $u->product->name,
            'uom_code' => $u->uom->code, 'factor' => (string) $u->factor, 'decimals' => $u->uom->decimals,
            'units' => ProductUom::with('uom')->where('product_id', $u->product_id)->get()
                ->map(fn ($x) => ['product_uom_id' => $x->id, 'uom_code' => $x->uom->code, 'factor' => (string) $x->factor])->values()];
    }

    public function purchaseOrders(Request $request): JsonResponse
    {
        $w = $this->warehouse($request, (string) $request->query('warehouse_id'), 'stock.receive');

        return response()->json(PurchaseOrder::with('supplier', 'lines')->where('warehouse_id', $w->id)
            ->whereIn('status', ['approved', 'partially_received'])->orderBy('expected_on')->orderBy('number')->limit(100)->get()
            ->map(fn ($po) => ['id' => $po->id, 'number' => $po->number, 'supplier' => $po->supplier->name, 'expected_on' => $po->expected_on?->toDateString(),
                'lines' => $po->lines->map(fn ($l) => ['id' => $l->id, 'product_uom_id' => $l->product_uom_id, 'sku' => $l->sku, 'name' => $l->name,
                    'track_batches' => (bool) Product::find($l->product_id)?->track_batches, 'track_serials' => (bool) Product::find($l->product_id)?->track_serials,
                    'uom_code' => $l->uom_code, 'ordered' => (string) $l->qty, 'received' => (string) $l->received_qty,
                    'barcodes' => Barcode::where('product_uom_id', $l->product_uom_id)->pluck('code')])->values()]));
    }

    public function receive(Request $request, GoodsReceiptService $grns): JsonResponse
    {
        $data = $request->validate([
            'purchase_order_id' => ['required', 'uuid'], 'client_id' => ['required', 'uuid'], 'supplier_ref' => ['nullable', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:500'], 'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.purchase_order_line_id' => ['required', 'uuid'], 'lines.*.qty' => ['required', 'numeric', 'min:0'], 'lines.*.bin_id' => ['nullable', 'uuid'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:60'], 'lines.*.expires_on' => ['nullable', 'date'],
            'lines.*.serials' => ['nullable', 'array', 'max:500'], 'lines.*.serials.*' => ['string', 'max:80'],
        ]);
        $po = PurchaseOrder::findOrFail($data['purchase_order_id']);
        $this->warehouse($request, $po->warehouse_id, 'stock.receive');
        $grn = $grns->receive($this->actor($request), $po, $data);

        return response()->json(['id' => $grn->id, 'number' => $grn->number, 'status' => $grn->status], 201);
    }

    public function binStock(Request $request): JsonResponse
    {
        $bin = Bin::findOrFail((string) $request->query('bin_id'));
        $this->warehouse($request, $bin->warehouse_id, 'stock.receive');

        return response()->json(DB::table('stock_bin_levels')->join('products', 'products.id', '=', 'stock_bin_levels.product_id')
            ->where('bin_id', $bin->id)->where('on_hand', '<>', 0)->orderBy('products.name')
            ->get(['products.id as product_id', 'products.sku', 'products.name', 'on_hand']));
    }

    public function putAway(Request $request, BinService $bins): JsonResponse
    {
        $data = $request->validate(['warehouse_id' => ['required', 'uuid'], 'product_uom_id' => ['required', 'uuid'], 'qty' => ['required', 'numeric', 'gt:0'],
            'from_bin_id' => ['required', 'uuid'], 'to_bin_id' => ['required', 'uuid']]);
        $w = Warehouse::where('branch_id', $this->device($request)->branch_id)->findOrFail($data['warehouse_id']);
        $unit = ProductUom::findOrFail($data['product_uom_id']);
        $bins->putAway($this->actor($request), $w, Product::findOrFail($unit->product_id), $unit, (string) $data['qty'], $data['from_bin_id'], $data['to_bin_id']);

        return response()->json(['ok' => true]);
    }

    public function transfers(Request $request): JsonResponse
    {
        $w = $this->warehouse($request, (string) $request->query('warehouse_id'), 'stock.transfer');

        return response()->json(StockTransfer::with('lines', 'fromWarehouse')->where('to_warehouse_id', $w->id)->where('status', 'in_transit')
            ->orderBy('dispatched_at')->get()->map(fn ($t) => ['id' => $t->id, 'number' => $t->number, 'from' => $t->fromWarehouse->name,
                'dispatched_at' => $t->dispatched_at?->toIso8601String(),
                'lines' => $t->lines->map(fn ($l) => ['id' => $l->id, 'sku' => $l->sku, 'name' => $l->name, 'uom_code' => $l->uom_code, 'qty' => (string) $l->qty,
                    'product_uom_id' => $l->product_uom_id, 'barcodes' => Barcode::where('product_uom_id', $l->product_uom_id)->pluck('code')])->values()]));
    }

    public function receiveTransfer(Request $request, string $id, TransferService $transfers): JsonResponse
    {
        $data = $request->validate(['lines' => ['required', 'array'], 'lines.*' => ['numeric', 'min:0'], 'to_bin_id' => ['nullable', 'uuid'], 'note' => ['nullable', 'string', 'max:500']]);
        $t = StockTransfer::findOrFail($id);
        $this->warehouse($request, $t->to_warehouse_id, 'stock.transfer');
        if ($t->status === 'received') {
            return response()->json(['id' => $t->id, 'number' => $t->number, 'status' => 'received']); // a resend after a lost answer
        }
        $t = $transfers->receive($this->actor($request), $t, array_map('strval', $data['lines']), $data['to_bin_id'] ?? null, $data['note'] ?? null);

        return response()->json(['id' => $t->id, 'number' => $t->number, 'status' => $t->status, 'has_discrepancy' => $t->has_discrepancy]);
    }

    public function counts(Request $request): JsonResponse
    {
        $w = $this->warehouse($request, (string) $request->query('warehouse_id'), 'stock.count');

        return response()->json(StockCount::with('bin')->where('warehouse_id', $w->id)->where('status', 'counting')->orderBy('started_at')->get()
            ->map(fn ($c) => ['id' => $c->id, 'number' => $c->number, 'bin' => $c->bin?->code, 'bin_id' => $c->bin_id, 'blind' => $c->blind,
                'counted' => $c->lines()->whereNotNull('counted_qty')->count(), 'lines' => $c->lines()->count()]));
    }

    public function startCount(Request $request, StockCountService $counts): JsonResponse
    {
        $data = $request->validate(['warehouse_id' => ['required', 'uuid'], 'bin_id' => ['nullable', 'uuid']]);
        $w = $this->warehouse($request, $data['warehouse_id'], 'stock.count');
        $c = $counts->start($this->actor($request), $w, isset($data['bin_id']) ? Bin::where('warehouse_id', $w->id)->findOrFail($data['bin_id']) : null);

        return response()->json(['id' => $c->id, 'number' => $c->number], 201);
    }

    public function countLine(Request $request, string $id, StockCountService $counts): JsonResponse
    {
        $data = $request->validate(['product_uom_id' => ['required', 'uuid'], 'qty' => ['required', 'numeric', 'min:0'], 'bin_id' => ['nullable', 'uuid']]);
        $c = StockCount::findOrFail($id);
        $this->warehouse($request, $c->warehouse_id, 'stock.count');
        $unit = ProductUom::findOrFail($data['product_uom_id']);
        $line = $counts->record($this->actor($request), $c, Product::findOrFail($unit->product_id), $unit, (string) $data['qty'], $data['bin_id'] ?? null);

        return response()->json(['ok' => true, 'counted_qty' => (string) $line->counted_qty]); // never the expected figure (blind)
    }

    public function submitCount(Request $request, string $id, StockCountService $counts): JsonResponse
    {
        $c = StockCount::findOrFail($id);
        $this->warehouse($request, $c->warehouse_id, 'stock.count');
        $c = $counts->submit($this->actor($request), $c);

        return response()->json(['id' => $c->id, 'number' => $c->number, 'status' => $c->status]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }
}
