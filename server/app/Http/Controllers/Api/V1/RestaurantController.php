<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Restaurant\DiningService;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DiningOrder;
use App\Models\DiningOrderItem;
use App\Models\DiningTable;
use App\Models\KitchenStation;
use App\Support\Qty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Restaurant at the till / waiter tablet (staff token) and on kitchen screens (the kitchen device's own token). */
class RestaurantController extends Controller
{
    public function __construct(private readonly DiningService $dining) {}

    /** Floor plan, stations and every open bill at the device's branch. */
    public function floor(Request $request): JsonResponse
    {
        $device = $this->device($request);

        return response()->json([
            'tables' => DiningTable::where('branch_id', $device->branch_id)->where('is_active', true)->orderBy('area')->orderBy('code')
                ->get(['id', 'code', 'area', 'seats', 'pos_x', 'pos_y']),
            'stations' => KitchenStation::where('branch_id', $device->branch_id)->where('is_active', true)->orderBy('code')->get(['code', 'name', 'auto_print']),
            'orders' => DiningService::openAt($device->branch_id)->map(fn ($o) => self::order($o))->values(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['order' => self::order($this->find($request, $id))]);
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'uuid'], 'kind' => ['required', 'in:table,tab,counter'], 'dining_table_id' => ['nullable', 'uuid'],
            'tab_name' => ['nullable', 'string', 'max:60'], 'covers' => ['nullable', 'integer', 'min:0', 'max:99'], 'customer_id' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['order' => self::order($this->dining->open($this->device($request), $request->user(), $data))], 201);
    }

    public function addItems(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.id' => ['required', 'uuid'], 'items.*.product_id' => ['required', 'uuid'],
            'items.*.product_uom_id' => ['required', 'uuid'], 'items.*.qty' => ['required', 'string', 'max:20'],
            'items.*.modifier_option_ids' => ['nullable', 'array', 'max:30'], 'items.*.modifier_option_ids.*' => ['uuid'],
            'items.*.note' => ['nullable', 'string', 'max:255'], 'items.*.course' => ['nullable', 'integer', 'min:0', 'max:9'],
            'items.*.seat' => ['nullable', 'integer', 'min:1', 'max:99'], 'items.*.hold' => ['nullable', 'boolean'],
        ]);
        $order = $this->dining->addItems($this->device($request), $request->user(), $this->find($request, $id), $data['items']);

        return response()->json(['order' => self::order($order)]);
    }

    public function fire(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['course' => ['nullable', 'integer', 'min:0', 'max:9']]);

        return response()->json(['order' => self::order($this->dining->fire($this->device($request), $request->user(), $this->find($request, $id), $data['course'] ?? null))]);
    }

    public function void(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1', 'max:100'], 'item_ids.*' => ['uuid'], 'reason' => ['required', 'string', 'max:255'],
            'approval.username' => ['nullable', 'string', 'max:60'], 'approval.pin' => ['nullable', 'string', 'max:8'],
        ]);
        $order = $this->dining->void($this->device($request), $request->user(), $this->find($request, $id), $data['item_ids'], $data['reason'],
            ! empty($data['approval']['username']) ? $data['approval'] : null);

        return response()->json(['order' => self::order($order)]);
    }

    public function serve(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['item_ids' => ['required', 'array', 'min:1', 'max:100'], 'item_ids.*' => ['uuid']]);

        return response()->json(['order' => self::order($this->dining->serve($this->device($request), $request->user(), $this->find($request, $id), $data['item_ids']))]);
    }

    public function move(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['dining_table_id' => ['required', 'uuid']]);

        return response()->json(['order' => self::order($this->dining->move($this->device($request), $request->user(), $this->find($request, $id), $data['dining_table_id']))]);
    }

    public function close(Request $request, string $id): JsonResponse
    {
        return response()->json(['order' => self::order($this->dining->close($this->device($request), $request->user(), $this->find($request, $id)))]);
    }

    /** Kitchen screen: dishes still to cook or collect at this branch (optionally one station), oldest first. */
    public function tickets(Request $request): JsonResponse
    {
        $kds = $this->kds($request);
        $station = $request->query('station') ? strtoupper((string) $request->query('station')) : null;
        $items = DiningOrderItem::with('order.table')->whereIn('status', ['fired', 'ready'])
            ->whereHas('order', fn ($q) => $q->where('branch_id', $kds->branch_id))
            ->when($station, fn ($q) => $q->where('station', $station))
            ->where('fired_at', '>', now()->subDay())->orderBy('fired_at')->limit(500)->get();

        return response()->json([
            'stations' => KitchenStation::where('branch_id', $kds->branch_id)->where('is_active', true)->orderBy('code')->get(['code', 'name', 'auto_print']),
            'tickets' => $items->groupBy('dining_order_id')->map(fn ($group) => [
                'order_id' => $group->first()->order->id, 'number' => $group->first()->order->number, 'label' => $group->first()->order->label(),
                'kind' => $group->first()->order->kind, 'waiter' => $group->first()->order->waiter_name, 'paid' => $group->first()->order->status !== 'open',
                'items' => $group->map(fn ($i) => self::item($i))->values(),
            ])->values(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function bump(Request $request): JsonResponse
    {
        $data = $request->validate(['item_ids' => ['required', 'array', 'min:1', 'max:100'], 'item_ids.*' => ['uuid'], 'status' => ['required', 'in:ready,fired,served']]);

        return response()->json(['changed' => $this->dining->bump($this->kds($request), $data['item_ids'], $data['status'])]);
    }

    public static function order(DiningOrder $o): array
    {
        $o->loadMissing(['items', 'table']);

        return [
            'id' => $o->id, 'number' => $o->number, 'kind' => $o->kind, 'label' => $o->label(), 'dining_table_id' => $o->dining_table_id, 'tab_name' => $o->tab_name,
            'covers' => $o->covers, 'customer_id' => $o->customer_id, 'waiter' => $o->waiter_name, 'status' => $o->status, 'note' => $o->note,
            'opened_at' => $o->opened_at->toIso8601String(), 'version' => $o->version,
            'items' => $o->items->map(fn ($i) => self::item($i))->values(),
        ];
    }

    private static function item(DiningOrderItem $i): array
    {
        return ['id' => $i->id, 'product_id' => $i->product_id, 'product_uom_id' => $i->product_uom_id, 'name' => $i->name, 'qty' => Qty::format(Qty::toMilli((string) $i->qty)),
            'modifiers' => $i->modifiers ?? [], 'note' => $i->note, 'course' => $i->course, 'seat' => $i->seat, 'station' => $i->station, 'status' => $i->status,
            'paid' => $i->sale_id !== null, 'added_by' => $i->added_by_name, 'fired_at' => $i->fired_at?->toIso8601String(), 'ready_at' => $i->ready_at?->toIso8601String(),
            'void_reason' => $i->void_reason];
    }

    private function find(Request $request, string $id): DiningOrder
    {
        return DiningOrder::with(['items', 'table'])->where('branch_id', $this->device($request)->branch_id)->findOrFail($id);
    }

    private function device(Request $request): Device
    {
        return $request->attributes->get('device');
    }

    private function kds(Request $request): Device
    {
        $d = $this->device($request);
        abort_unless($d->kind === 'kds', 403, 'Kitchen screens only.');

        return $d;
    }
}
