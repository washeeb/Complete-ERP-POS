<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Devices\PosAuthService;
use App\Domain\Restaurant\DiningService;
use App\Domain\Restaurant\ReservationService;
use App\Domain\Restaurant\WasteService;
use App\Domain\Support\Actor;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DiningOrder;
use App\Models\DiningOrderItem;
use App\Models\DiningTable;
use App\Models\KitchenStation;
use App\Models\Product;
use App\Models\Reservation;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Restaurant at the till / waiter tablet (staff token) and on kitchen screens (the kitchen device's own token). */
class RestaurantController extends Controller
{
    public function __construct(private readonly DiningService $dining, private readonly ReservationService $bookings, private readonly WasteService $waste) {}

    /** Floor plan, stations and every open bill at the device's branch. */
    public function floor(Request $request): JsonResponse
    {
        $device = $this->device($request);

        return response()->json([
            'tables' => DiningTable::where('branch_id', $device->branch_id)->where('is_active', true)->orderBy('area')->orderBy('code')
                ->get(['id', 'code', 'area', 'seats', 'pos_x', 'pos_y']),
            'stations' => KitchenStation::where('branch_id', $device->branch_id)->where('is_active', true)->orderBy('code')->get(['code', 'name', 'auto_print']),
            'orders' => DiningService::openAt($device->branch_id)->map(fn ($o) => self::order($o))->values(),
            'reservations' => ReservationService::upcoming($device->branch_id)->map(fn ($r) => self::booking($r))->values(),
            'hold_minutes' => (int) app(Settings::class)->get('restaurant.hold_minutes', $device->tenant_id),
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
            'id' => ['required', 'uuid'], 'kind' => ['required', 'in:table,tab,counter,delivery'], 'dining_table_id' => ['nullable', 'uuid'],
            'platform' => ['nullable', 'string', 'max:20'], 'external_ref' => ['nullable', 'string', 'max:80'],
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

    /** Bookings for a day at the device's branch (default today). */
    public function reservations(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $day = Carbon::parse((string) $request->query('date', now()->toDateString()), $this->tz($device))->startOfDay();

        return response()->json(['reservations' => Reservation::where('branch_id', $device->branch_id)->whereBetween('starts_at', [$day->copy()->utc(), $day->copy()->endOfDay()->utc()])
            ->orderBy('starts_at')->get()->map(fn ($r) => self::booking($r))->values()]);
    }

    public function book(Request $request, ?string $id = null): JsonResponse
    {
        $data = $request->validate([
            'guest_name' => [$id ? 'sometimes' : 'required', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:20'],
            'party_size' => [$id ? 'sometimes' : 'required', 'integer', 'min:1', 'max:200'], 'starts_at' => [$id ? 'sometimes' : 'required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:600'], 'dining_table_id' => ['nullable', 'uuid'], 'customer_id' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $device = $this->device($request);
        $r = $id ? $this->findBooking($request, $id) : new Reservation;
        $saved = $this->bookings->save(Actor::user($request->user(), 'pos', $device->id), $r, $data + ['branch_id' => $device->branch_id]);

        return response()->json(['reservation' => self::booking($saved)], $id ? 200 : 201);
    }

    public function deposit(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['method' => ['required', 'in:cash,momo,card'], 'amount_minor' => ['required', 'integer', 'min:1'],
            'provider' => ['nullable', 'string', 'max:20'], 'reference' => ['nullable', 'string', 'max:80']]);
        $r = $this->bookings->takeDeposit($this->device($request), $request->user(), $this->findBooking($request, $id), $data['method'], $data['amount_minor'],
            $data['provider'] ?? null, $data['reference'] ?? null);

        return response()->json(['reservation' => self::booking($r)]);
    }

    public function refund(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['method' => ['required', 'in:cash,momo,card'], 'provider' => ['nullable', 'string', 'max:20'], 'reference' => ['nullable', 'string', 'max:80'],
            'approval.username' => ['nullable', 'string', 'max:60'], 'approval.pin' => ['nullable', 'string', 'max:8']]);
        $r = $this->bookings->refundDeposit($this->device($request), $request->user(), $this->findBooking($request, $id), $data['method'],
            ! empty($data['approval']['username']) ? $data['approval'] : null, $data['provider'] ?? null, $data['reference'] ?? null);

        return response()->json(['reservation' => self::booking($r)]);
    }

    public function seat(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['dining_table_id' => ['nullable', 'uuid']]);

        return response()->json(['order' => self::order($this->bookings->seat($this->device($request), $request->user(), $this->findBooking($request, $id), $data['dining_table_id'] ?? null))]);
    }

    public function noShow(Request $request, string $id): JsonResponse
    {
        $r = $this->bookings->noShow(Actor::user($request->user(), 'pos', $this->device($request)->id), $this->findBooking($request, $id));

        return response()->json(['reservation' => self::booking($r)]);
    }

    public function cancelBooking(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $r = $this->bookings->cancel(Actor::user($request->user(), 'pos', $this->device($request)->id), $this->findBooking($request, $id), $data['reason']);

        return response()->json(['reservation' => self::booking($r)]);
    }

    /** Kitchen waste from the till (the signed-in person). */
    public function recordWaste(Request $request): JsonResponse
    {
        $data = $this->wasteInput($request);

        return response()->json($this->waste->record($this->device($request), $request->user(), $data['items'], $data['reason'],
            ! empty($data['approval']['username']) ? $data['approval'] : null), 201);
    }

    /** Kitchen waste from the kitchen screen: the cook identifies themselves with username and PIN. */
    public function kdsWaste(Request $request, PosAuthService $auth): JsonResponse
    {
        $kds = $this->kds($request);
        $data = $this->wasteInput($request) + $request->validate(['username' => ['required', 'string', 'max:60'], 'pin' => ['required', 'string', 'max:8']]);
        $user = $auth->verifyPin($kds, $data['username'], $data['pin']);

        return response()->json($this->waste->record($kds, $user, $data['items'], $data['reason'], ! empty($data['approval']['username']) ? $data['approval'] : null), 201);
    }

    /** What a kitchen screen can log as waste: dishes with a recipe and stocked ingredients (base units). */
    public function kdsProducts(Request $request): JsonResponse
    {
        $this->kds($request);
        $withRecipe = DB::table('recipe_lines')->distinct()->pluck('product_id');

        return response()->json(['products' => Product::with(['units' => fn ($q) => $q->where('is_base', true), 'units.uom'])->where('is_active', true)
            ->where(fn ($q) => $q->where('track_stock', true)->orWhereIn('id', $withRecipe))->orderBy('name')->limit(2000)->get()
            ->map(fn ($p) => ['id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'units' => $p->units->map(fn ($u) => ['id' => $u->id, 'uom_code' => $u->uom->code, 'decimals' => $u->uom->decimals])->values()])->values()]);
    }

    private function wasteInput(Request $request): array
    {
        return $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'], 'items.*.product_id' => ['required', 'uuid'], 'items.*.product_uom_id' => ['required', 'uuid'],
            'items.*.qty' => ['required', 'string', 'max:20'], 'reason' => ['required', 'string', 'max:120'],
            'approval.username' => ['nullable', 'string', 'max:60'], 'approval.pin' => ['nullable', 'string', 'max:8'],
        ]);
    }

    public static function booking(Reservation $r): array
    {
        return ['id' => $r->id, 'number' => $r->number, 'guest_name' => $r->guest_name, 'phone' => $r->phone, 'party_size' => $r->party_size,
            'starts_at' => $r->starts_at->toIso8601String(), 'duration_minutes' => $r->duration_minutes, 'dining_table_id' => $r->dining_table_id,
            'customer_id' => $r->customer_id, 'note' => $r->note, 'status' => $r->status, 'deposit_minor' => $r->deposit_minor, 'deposit_left_minor' => $r->depositLeft(),
            'deposit_status' => $r->deposit_status, 'dining_order_id' => $r->dining_order_id];
    }

    private function findBooking(Request $request, string $id): Reservation
    {
        return Reservation::where('branch_id', $this->device($request)->branch_id)->findOrFail($id);
    }

    private function tz(Device $device): string
    {
        return Branch::find($device->branch_id)?->timezone ?: (string) config('app.timezone');
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
            'opened_at' => $o->opened_at->toIso8601String(), 'version' => $o->version, 'platform' => $o->platform, 'external_ref' => $o->external_ref,
            'reservation' => $o->reservation_id ? (fn ($r) => $r ? ['number' => $r->number, 'deposit_left_minor' => $r->depositLeft()] : null)(Reservation::find($o->reservation_id)) : null,
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
