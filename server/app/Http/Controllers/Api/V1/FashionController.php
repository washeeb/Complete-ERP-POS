<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Fashion\AlterationService;
use App\Domain\Support\Actor;
use App\Http\Controllers\Controller;
use App\Models\Alteration;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Fashion at the till: alteration jobs. */
class FashionController extends Controller
{
    public function __construct(private readonly AlterationService $alterations) {}

    public function index(Request $request): JsonResponse
    {
        $list = Alteration::where('branch_id', $this->device($request)->branch_id)->whereIn('status', Alteration::OPEN)->orderBy('due_on')->orderBy('received_at')->limit(200)->get();

        return response()->json(['alterations' => $list->map(fn ($a) => self::json($a))->values(),
            'product_id' => $this->alterations->product($this->device($request)->tenant_id)?->id]);
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate(['customer_id' => ['nullable', 'uuid'], 'customer_name' => ['nullable', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:20'],
            'garment' => ['required', 'string', 'max:160'], 'work' => ['required', 'string', 'max:2000'], 'charge_minor' => ['required', 'integer', 'min:0'], 'due_on' => ['nullable', 'date']]);

        return response()->json(['alteration' => self::json($this->alterations->open($this->actor($request), $data + ['branch_id' => $this->device($request)->branch_id]))], 201);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'string', 'max:20'], 'tailor' => ['nullable', 'string', 'max:120']]);

        return response()->json(['alteration' => self::json($this->alterations->setStatus($this->actor($request), $this->find($request, $id), $data['status'], $data['tailor'] ?? null))]);
    }

    public function handOver(Request $request, string $id): JsonResponse
    {
        return response()->json(['alteration' => self::json($this->alterations->handOver($this->actor($request), $this->find($request, $id)))]);
    }

    public static function json(Alteration $a): array
    {
        return ['id' => $a->id, 'number' => $a->number, 'customer_id' => $a->customer_id, 'customer_name' => $a->customer_name, 'phone' => $a->phone, 'garment' => $a->garment,
            'work' => $a->work, 'charge_minor' => $a->charge_minor, 'status' => $a->status, 'status_label' => Alteration::STATUSES[$a->status] ?? $a->status,
            'due_on' => $a->due_on?->toDateString(), 'tailor_name' => $a->tailor_name, 'received_at' => $a->received_at->toIso8601String()];
    }

    private function find(Request $request, string $id): Alteration
    {
        return Alteration::where('branch_id', $this->device($request)->branch_id)->findOrFail($id);
    }

    private function device(Request $request): Device
    {
        return $request->attributes->get('device');
    }

    private function actor(Request $request): Actor
    {
        return Actor::user($request->user(), 'pos', $this->device($request)->id);
    }
}
