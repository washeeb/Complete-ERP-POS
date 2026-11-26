<?php

namespace App\Http\Controllers\Office;

use App\Domain\Electronics\RepairService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Office\ElectronicsScreen;
use App\Domain\Office\StockScreen;
use App\Domain\Support\Actor;
use App\Filament\Resources\RepairJobs\RepairJobResource;
use App\Filament\Resources\SerialNumbers\SerialNumberResource;
use App\Filament\Support\Erp;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Electronics in the new back office (App\Domain\Office\ElectronicsScreen): repairs (new job, update progress — sent to
 * the supplier with the RMA —, parts and labour, hand over without charge) and serial numbers (register numbers for stock
 * on hand) — through the services the current pages use.
 */
class ElectronicsController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $r, bool $can): ElectronicsScreen
    {
        $this->allowed($can);

        return new ElectronicsScreen($r->user());
    }

    // -------------------------------------------------------------------------------------------- Repairs

    public function repairs(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, RepairJobResource::canViewAny())->repairs($request->query()));
    }

    public function repairStore(Request $request, RepairService $svc): JsonResponse
    {
        $this->screen($request, RepairJobResource::canCreate());
        $d = $request->validate(['branch_id' => ['required', 'uuid'], 'customer_name' => ['required', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:20'],
            'serial' => ['nullable', 'string', 'max:80'], 'device' => ['nullable', 'string', 'max:160'], 'accessories' => ['nullable', 'string', 'max:255'],
            'fault' => ['required', 'string', 'max:2000'], 'promised_on' => ['nullable', 'date']],
            ['branch_id.*' => 'Choose the branch.', 'customer_name.*' => 'Enter the customer.', 'fault.*' => 'Say what is wrong.']);
        $d['promised_on'] = ($d['promised_on'] ?? '') ?: null;

        return $this->act(fn (Actor $actor) => ['id' => $svc->open($actor, $d)->id, 'message' => 'Repair job opened.']);
    }

    public function repair(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, RepairJobResource::canViewAny());

        return response()->json($screen->repair($screen->repairsQuery()->findOrFail($id)));
    }

    /** progress (status, note; supplier and RMA when it goes back to the supplier) · lines (parts and labour) · hand_over. */
    public function repairAction(Request $request, string $id, RepairService $svc): JsonResponse
    {
        $job = $this->screen($request, RepairJobResource::canViewAny())->repairsQuery()->findOrFail($id);
        switch ((string) $request->input('action')) {
            case 'progress':
                $d = $request->validate(['status' => ['required', 'string'], 'note' => ['nullable', 'string', 'max:500'], 'supplier_id' => ['nullable', 'uuid'],
                    'rma_reference' => ['nullable', 'string', 'max:80']], ['status.*' => 'Choose where the job stands.']);

                return $this->act(fn (Actor $actor) => ['message' => $svc->setStatus($actor, $job, $d['status'], ($d['note'] ?? '') ?: null, ($d['supplier_id'] ?? '') ?: null,
                    ($d['rma_reference'] ?? '') ?: null) ? 'Updated.' : '']);
            case 'lines':
                $d = $request->validate(['lines' => ['present', 'array'], 'lines.*.product_uom_id' => ['required', 'uuid'], 'lines.*.qty' => ['required', 'numeric', 'min:0.001']],
                    ['lines.*.product_uom_id.*' => 'Choose the part or labour on every line.', 'lines.*.qty.*' => 'Enter the quantity on every line.']);

                return $this->act(fn (Actor $actor) => ['message' => $svc->setLines($actor, $job, array_map(fn ($l) => ['product_uom_id' => $l['product_uom_id'], 'qty' => (string) $l['qty']],
                    $d['lines'])) ? 'Saved — priced from the price list.' : '']);
            case 'hand_over':
                $wh = $request->validate(['warehouse_id' => ['required', 'uuid']], ['warehouse_id.*' => 'Choose where the parts were taken from.'])['warehouse_id'];
                $warehouse = Warehouse::where('branch_id', $job->branch_id)->findOrFail($wh);

                return $this->act(fn (Actor $actor) => ['message' => $svc->handOver($actor, $job, $warehouse) ? 'Handed over.' : '']);
        }
        abort(422, 'Unknown action.');
    }

    public function units(Request $request): JsonResponse
    {
        $this->screen($request, RepairJobResource::canViewAny());

        return response()->json(['results' => (new StockScreen($request->user()))->units(mb_substr(trim((string) $request->query('q', '')), 0, 60), false)]);
    }

    public function suppliers(Request $request): JsonResponse
    {
        $screen = $this->screen($request, RepairJobResource::canViewAny());

        return response()->json(['results' => $screen->suppliers(mb_substr(trim((string) $request->query('q', '')), 0, 60))]);
    }

    // -------------------------------------------------------------------------------------------- Serial numbers

    public function serials(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, SerialNumberResource::canViewAny())->serials($request->query()));
    }

    public function serial(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, SerialNumberResource::canViewAny());

        return response()->json($screen->serial($screen->serialsQuery()->findOrFail($id)));
    }

    public function serialProducts(Request $request): JsonResponse
    {
        $screen = $this->screen($request, SerialNumberResource::canViewAny() && Erp::canAnywhere('stock.adjust'));

        return response()->json(['results' => $screen->serialProducts(mb_substr(trim((string) $request->query('q', '')), 0, 60))]);
    }

    /** Register numbers for units already in stock without one. */
    public function register(Request $request, InventoryService $svc): JsonResponse
    {
        $this->screen($request, SerialNumberResource::canViewAny() && Erp::canAnywhere('stock.adjust'));
        $d = $request->validate(['warehouse_id' => ['required', 'uuid'], 'product_id' => ['required', 'uuid'], 'serials' => ['required', 'array', 'min:1'], 'serials.*' => ['string', 'max:80']],
            ['warehouse_id.*' => 'Choose the warehouse.', 'product_id.*' => 'Choose the product.', 'serials.*' => 'Enter the serial / IMEI numbers.']);
        $warehouse = Warehouse::findOrFail($d['warehouse_id']);
        $product = Product::where('track_serials', true)->findOrFail($d['product_id']);

        return $this->act(function (Actor $actor) use ($svc, $warehouse, $product, $d) {
            $n = $svc->registerSerials($actor, $warehouse, $product, $d['serials']);

            return ['message' => "{$n} serial number(s) registered."];
        });
    }
}
