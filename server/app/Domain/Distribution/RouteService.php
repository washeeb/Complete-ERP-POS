<?php

namespace App\Domain\Distribution;

use App\Domain\Sales\CustomerOrderService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\RepTarget;
use App\Models\RouteStop;
use App\Models\SalesRoute;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\User;
use App\Models\Visit;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Routes, visits, rep targets and orders taken on the road.
 *
 * - A route lists customers in visiting order and the weekdays it runs, usually from one van. The rep's phone gets
 *   today's route with the catalog, so it works offline.
 * - A visit records each call: sold, took an order, no order or closed, with the phone's GPS position. Visits made
 *   offline arrive with the till's queue; each has its own id, so sending twice is harmless.
 * - Orders taken for later delivery become customer orders at the van's home warehouse (credit checked as usual —
 *   an order over the limit waits on credit hold).
 */
final class RouteService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly CustomerOrderService $orders,
    ) {}

    /** @param array{code?: string, name?: string, branch_id?: string, van_warehouse_id?: ?string, rep_user_id?: ?string, days?: list<int>, is_active?: bool, customer_ids?: list<string>} $data */
    public function save(Actor $actor, SalesRoute $route, array $data): SalesRoute
    {
        $branch = Branch::findOrFail($data['branch_id'] ?? $route->branch_id);
        if (! $actor->user || ! $this->authz->allows($actor->user, 'distribution.routes', $branch)) {
            throw new AccessDenied;
        }
        $route->fill(array_intersect_key($data, array_flip(['code', 'name', 'van_warehouse_id', 'rep_user_id', 'is_active'])));
        $route->code = strtoupper(trim((string) $route->code));
        $route->name = trim((string) $route->name);
        if (! preg_match('/^[A-Z0-9][A-Z0-9_-]{0,19}$/', $route->code) || $route->name === '') {
            throw new DomainException('Enter a route code (letters, digits, dash) and a name.');
        }
        if (SalesRoute::where('code', $route->code)->when($route->exists, fn ($q) => $q->whereKeyNot($route->id))->exists()) {
            throw new DomainException("Route {$route->code} already exists.");
        }
        if ($route->van_warehouse_id && ! Warehouse::where('branch_id', $branch->id)->where('is_van', true)->whereKey($route->van_warehouse_id)->exists()) {
            throw new DomainException('Choose a van of this branch.');
        }
        if (array_key_exists('days', $data)) {
            $days = array_values(array_unique(array_map('intval', (array) $data['days'])));
            if (array_diff($days, array_keys(SalesRoute::DAYS))) {
                throw new DomainException('Days are 1 (Monday) to 7 (Sunday).');
            }
            sort($days);
            $route->days = $days;
        }
        $route->days ??= [];
        $ids = array_key_exists('customer_ids', $data) ? array_values(array_unique(array_filter((array) $data['customer_ids']))) : null;
        if ($ids !== null && Customer::whereIn('id', $ids)->count() !== count($ids)) {
            throw new DomainException('A customer on the route is not known.');
        }

        return $this->recorder->transaction(function () use ($actor, $route, $branch, $ids) {
            $isNew = ! $route->exists;
            $route->forceFill(['branch_id' => $branch->id, 'tenant_id' => $route->tenant_id ?? $actor->tenantId]);
            $this->recorder->save($actor, $route, $isNew ? 'route.created' : 'route.updated', branchId: $branch->id);
            if ($ids !== null) {
                RouteStop::where('route_id', $route->id)->delete();
                foreach ($ids as $i => $id) {
                    RouteStop::create(['route_id' => $route->id, 'customer_id' => $id, 'sequence' => $i + 1]);
                }
                $this->recorder->audit->record($actor, 'route.stops_changed', $route, ['stops' => [null, count($ids)]], null, $branch->id);
                $this->recorder->outbox->publish($actor, $route->fresh('stops'), 'route.stops_changed');
            }

            return $route->fresh('stops');
        });
    }

    /** Today's route for a till (a van's): its routes that run on this weekday, with the stops in order. */
    public static function today(Till $till, ?Carbon $at = null): ?array
    {
        $van = Warehouse::find($till->warehouse_id);
        if (! $van?->is_van) {
            return null;
        }
        $branch = Branch::find($till->branch_id);
        $tz = $branch?->timezone ?: (Tenant::find($till->tenant_id)?->timezone ?: 'UTC');
        $day = ($at ?? now())->copy()->setTimezone($tz)->isoWeekday();
        $routes = SalesRoute::with('stops')->where('van_warehouse_id', $van->id)->where('is_active', true)->get()->filter(fn ($r) => in_array($day, $r->days ?? [], true));
        $stops = [];
        foreach ($routes as $r) {
            foreach ($r->stops as $s) {
                $stops[] = ['route_id' => $r->id, 'customer_id' => $s->customer_id, 'sequence' => $s->sequence];
            }
        }

        return ['van' => ['id' => $van->id, 'code' => $van->code, 'name' => $van->name, 'vehicle_reg' => $van->vehicle_reg],
            'routes' => $routes->map(fn ($r) => ['id' => $r->id, 'code' => $r->code, 'name' => $r->name])->values()->all(), 'stops' => $stops, 'weekday' => $day];
    }

    /**
     * A visit from the rep's phone (online, or later from the offline queue). Idempotent on its id.
     *
     * @param  array{id: string, customer_id: string, route_id?: ?string, outcome: string, note?: ?string, lat?: ?float, lng?: ?float, accuracy_m?: ?int, sale_id?: ?string, customer_order_id?: ?string, occurred_at: string}  $p
     */
    public function visit(Device $device, User $user, array $p): array
    {
        if (! Str::isUuid($p['id'] ?? '')) {
            throw new DomainException('Missing visit id.', 'malformed');
        }
        if (Visit::whereKey($p['id'])->exists()) {
            return ['status' => 'duplicate'];
        }
        if (! array_key_exists($p['outcome'] ?? '', Visit::OUTCOMES)) {
            throw new DomainException('Choose what happened at the visit.');
        }
        $customer = Customer::find($p['customer_id'] ?? null) ?? throw new DomainException('That customer is not known here.', 'unknown_customer');
        $till = Till::findOrFail($device->till_id);
        $lat = isset($p['lat']) && is_numeric($p['lat']) && abs((float) $p['lat']) <= 90 ? round((float) $p['lat'], 6) : null;
        $lng = isset($p['lng']) && is_numeric($p['lng']) && abs((float) $p['lng']) <= 180 ? round((float) $p['lng'], 6) : null;
        $actor = Actor::user($user, 'pos', $device->id);

        $this->recorder->transaction(function () use ($p, $device, $user, $customer, $till, $lat, $lng, $actor) {
            $v = (new Visit)->forceFill(['id' => $p['id'], 'tenant_id' => $device->tenant_id, 'branch_id' => $till->branch_id,
                'route_id' => $p['route_id'] ?? null, 'customer_id' => $customer->id, 'user_id' => $user->id, 'user_name' => $user->name, 'device_id' => $device->id,
                'outcome' => $p['outcome'], 'note' => isset($p['note']) ? mb_substr(trim((string) $p['note']), 0, 255) ?: null : null,
                'lat' => $lat, 'lng' => $lng, 'accuracy_m' => isset($p['accuracy_m']) ? min(100000, max(0, (int) $p['accuracy_m'])) : null,
                'sale_id' => $p['sale_id'] ?? null, 'customer_order_id' => $p['customer_order_id'] ?? null,
                'occurred_at' => Carbon::parse($p['occurred_at'] ?? 'now'), 'created_at' => now()]);
            $v->save();
            $this->recorder->outbox->publish($actor, $v, 'visit.recorded');
        });

        return ['status' => 'accepted'];
    }

    /**
     * An order taken on the road, for delivery from the van's home warehouse. Idempotent on its id.
     *
     * @param  array{id: string, customer_id: string, lines: list<array{product_uom_id: string, qty: string}>, note?: ?string, customer_ref?: ?string}  $p
     */
    public function takeOrder(Device $device, User $user, array $p): array
    {
        if (! Str::isUuid($p['id'] ?? '')) {
            throw new DomainException('Missing order id.', 'malformed');
        }
        if ($existing = CustomerOrder::find($p['id'])) {
            return ['status' => 'duplicate', 'order' => $existing];
        }
        $till = Till::findOrFail($device->till_id);
        $van = Warehouse::findOrFail($till->warehouse_id);
        $warehouseId = $van->is_van ? $van->home_warehouse_id : $van->id;
        $actor = Actor::user($user, 'pos', $device->id);
        $order = $this->recorder->transaction(function () use ($actor, $p, $warehouseId) {
            $order = new CustomerOrder;
            $order->id = $p['id'];
            $order = $this->orders->saveQuotation($actor, $order, ['warehouse_id' => $warehouseId, 'customer_id' => $p['customer_id'], 'lines' => $p['lines'],
                'customer_ref' => $p['customer_ref'] ?? null, 'note' => $p['note'] ?? null]);

            return $this->orders->confirm($actor, $order);
        });

        return ['status' => 'accepted', 'order' => $order];
    }

    /** @param array{user_id: string, year: int, month: int, target: string, visit_target?: ?int} $data */
    public function setTarget(Actor $actor, array $data): RepTarget
    {
        if (! $actor->user || ! $this->authz->allowsAnywhere($actor->user, 'distribution.routes')) {
            throw new AccessDenied;
        }
        $user = User::findOrFail($data['user_id'] ?? null);
        $year = (int) $data['year'];
        $month = (int) $data['month'];
        if ($month < 1 || $month > 12 || $year < 2000) {
            throw new DomainException('Choose the month.');
        }
        $target = Money::toMinor((string) $data['target'], Tenant::find($actor->tenantId)->currency_code);
        if ($target < 0) {
            throw new DomainException('A target cannot be negative.');
        }
        $t = RepTarget::firstOrNew(['user_id' => $user->id, 'year' => $year, 'month' => $month]);
        $t->forceFill(['tenant_id' => $actor->tenantId, 'target_minor' => $target, 'visit_target' => isset($data['visit_target']) && $data['visit_target'] !== '' ? (int) $data['visit_target'] : null]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $t, $t->exists ? 'rep_target.updated' : 'rep_target.set'));
    }
}
