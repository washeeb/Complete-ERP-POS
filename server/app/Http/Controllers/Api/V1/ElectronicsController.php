<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Electronics\RepairService;
use App\Domain\Inventory\SerialRegistry;
use App\Domain\Support\Actor;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Device;
use App\Models\RepairJob;
use App\Models\Sale;
use App\Models\SerialNumber;
use App\Models\Till;
use App\Models\Warehouse;
use App\Support\Qty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Electronics at the till: serial / warranty lookup and repair job cards. */
class ElectronicsController extends Controller
{
    public function __construct(private readonly RepairService $repairs) {}

    /** A serial / IMEI number: what it is, where it is, who bought it and whether it is under warranty. */
    public function serial(Request $request, string $serial): JsonResponse
    {
        $s = SerialRegistry::normalise([$serial])[0] ?? '';
        $rows = SerialNumber::with('product')->where('serial', $s)->get();

        return response()->json(['serials' => $rows->map(fn ($r) => [
            'serial' => $r->serial, 'product_id' => $r->product_id, 'product' => $r->product?->name, 'status' => $r->status,
            'status_label' => SerialNumber::STATUSES[$r->status] ?? $r->status, 'in_stock_here' => $r->status === 'in_stock' && $r->warehouse_id === $this->warehouseId($request),
            'sold_at' => $r->sold_at?->toIso8601String(), 'receipt_no' => $r->sale_id ? Sale::whereKey($r->sale_id)->value('receipt_no') : null,
            'customer' => $r->customer_id ? Customer::whereKey($r->customer_id)->value('name') : null,
            'warranty_until' => $r->warranty_until?->toDateString(), 'in_warranty' => $r->inWarranty(),
        ])->values()]);
    }

    public function index(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $open = $request->query('status', 'open') === 'open';
        $jobs = RepairJob::with('lines')->where('branch_id', $device->branch_id)
            ->when($open, fn ($q) => $q->whereIn('status', RepairJob::OPEN), fn ($q) => $q->where('updated_at', '>', now()->subDays(30)))
            ->orderByDesc('received_at')->limit(200)->get();

        return response()->json(['jobs' => $jobs->map(fn ($j) => self::job($j))->values()]);
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'uuid'], 'customer_name' => ['nullable', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:20'],
            'serial' => ['nullable', 'string', 'max:80'], 'device' => ['nullable', 'string', 'max:160'], 'fault' => ['required', 'string', 'max:2000'],
            'accessories' => ['nullable', 'string', 'max:255'], 'estimate_minor' => ['nullable', 'integer', 'min:0'], 'promised_on' => ['nullable', 'date'],
        ]);

        return response()->json(['job' => self::job($this->repairs->open($this->actor($request), $data + ['branch_id' => $this->device($request)->branch_id]))], 201);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'string', 'max:20'], 'note' => ['nullable', 'string', 'max:500'],
            'supplier_id' => ['nullable', 'uuid'], 'rma_reference' => ['nullable', 'string', 'max:80']]);
        $job = $this->repairs->setStatus($this->actor($request), $this->find($request, $id), $data['status'], $data['note'] ?? null, $data['supplier_id'] ?? null, $data['rma_reference'] ?? null);

        return response()->json(['job' => self::job($job->load('lines'))]);
    }

    public function lines(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['lines' => ['present', 'array', 'max:50'], 'lines.*.product_uom_id' => ['required', 'uuid'], 'lines.*.qty' => ['required', 'string', 'max:20']]);

        return response()->json(['job' => self::job($this->repairs->setLines($this->actor($request), $this->find($request, $id), $data['lines']))]);
    }

    public function handOver(Request $request, string $id): JsonResponse
    {
        $till = Till::findOrFail($this->device($request)->till_id);

        return response()->json(['job' => self::job($this->repairs->handOver($this->actor($request), $this->find($request, $id), Warehouse::findOrFail($till->warehouse_id))->load('lines'))]);
    }

    public static function job(RepairJob $j): array
    {
        return ['id' => $j->id, 'number' => $j->number, 'customer_id' => $j->customer_id, 'customer_name' => $j->customer_name, 'phone' => $j->phone,
            'device' => $j->device, 'serial' => $j->serial, 'fault' => $j->fault, 'accessories' => $j->accessories, 'status' => $j->status,
            'status_label' => RepairJob::STATUSES[$j->status] ?? $j->status, 'in_warranty' => $j->in_warranty, 'estimate_minor' => $j->estimate_minor,
            'promised_on' => $j->promised_on?->toDateString(), 'received_at' => $j->received_at->toIso8601String(), 'rma_reference' => $j->rma_reference, 'log' => $j->log ?? [],
            'lines' => $j->lines->map(fn ($l) => ['product_id' => $l->product_id, 'product_uom_id' => $l->product_uom_id, 'name' => $l->name,
                'qty' => Qty::format(Qty::toMilli((string) $l->qty)), 'unit_price_minor' => $l->unit_price_minor])->values()];
    }

    private function find(Request $request, string $id): RepairJob
    {
        return RepairJob::with('lines')->where('branch_id', $this->device($request)->branch_id)->findOrFail($id);
    }

    private function device(Request $request): Device
    {
        return $request->attributes->get('device');
    }

    private function actor(Request $request): Actor
    {
        return Actor::user($request->user(), 'pos', $this->device($request)->id);
    }

    private function warehouseId(Request $request): ?string
    {
        return ($t = Till::find($this->device($request)->till_id)) ? $t->warehouse_id : null;
    }
}
