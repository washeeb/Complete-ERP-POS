<?php

namespace App\Domain\Office;

use App\Domain\Distribution\DistributionReports;
use App\Filament\Pages\DistributionReport;
use App\Filament\Resources\RepTargets\RepTargetResource;
use App\Filament\Resources\SalesRoutes\SalesRouteResource;
use App\Filament\Resources\Vans\VanResource;
use App\Filament\Resources\VanSettlements\VanSettlementResource;
use App\Filament\Resources\Visits\VisitResource;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\RepTarget;
use App\Models\SalesRoute;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VanSettlement;
use App\Models\Visit;
use App\Models\Warehouse;
use App\Support\IndustryContext;
use App\Support\Money;
use App\Support\Qty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Sales & distribution in the new back office (part 34 of the rewrite), as the current pages: Vans, Van settlements,
 * Routes, Visits, Rep targets and Rep performance. Same queries (the resources' and page's, scoped to the person's
 * branches) and rules: the VanService, VanSettlementService and RouteService services (DistributionController).
 */
final class DistributionScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const SETTLEMENT_TONE = ['posted' => 'success', 'submitted' => 'warning', 'cancelled' => 'subtle', 'counting' => 'informative'];

    public function __construct(private readonly User $user) {}

    private static function choices(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private static function page(array $p): array
    {
        return [in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25, max(1, (int) ($p['page'] ?? 1))];
    }

    private function m(): array
    {
        $c = $this->user->tenant?->currency_code ?? 'GHS';

        return ['currency' => $c, 'exponent' => Money::exponent($c)];
    }

    private static function qty(mixed $v): string
    {
        return $v === null ? '' : Qty::format(Qty::toMilli((string) $v));
    }

    private static function shown(Builder $q, array $p, string $table): void
    {
        match ($p['show'] ?? '') {
            'archived' => $q->whereNotNull("{$table}.archived_at"), 'both' => null, default => $q->whereNull("{$table}.archived_at")
        };
    }

    /** People who can be a van's or route's rep. */
    private function reps(): array
    {
        return self::choices(IndustryContext::userScope(User::where('is_active', true))->orderBy('name')->pluck('name', 'id')->all());
    }

    public static function canVans(): bool
    {
        return VanResource::canViewAny();
    }

    public static function canSettlements(): bool
    {
        return VanSettlementResource::canViewAny();
    }

    public static function canRoutes(): bool
    {
        return SalesRouteResource::canViewAny();
    }

    public static function canVisits(): bool
    {
        return VisitResource::canViewAny();
    }

    public static function canTargets(): bool
    {
        return RepTargetResource::canViewAny();
    }

    public static function canPerformance(): bool
    {
        return DistributionReport::canAccess();
    }

    // -------------------------------------------------------------------------------------------- Vans

    public function vansQuery(): Builder
    {
        return VanResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  q, show */
    public function vans(array $p): array
    {
        $q = $this->vansQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('warehouses.code', 'ilike', "%{$s}%")->orWhere('warehouses.name', 'ilike', "%{$s}%"));
        }
        self::shown($q, $p, 'warehouses');
        $vans = $q->orderBy('warehouses.code')->get();
        $users = User::whereIn('id', $vans->pluck('rep_user_id')->filter())->pluck('name', 'id');
        $homes = Warehouse::whereIn('id', $vans->pluck('home_warehouse_id')->filter())->pluck('code', 'id');
        $stock = DB::table('stock_levels')->whereIn('warehouse_id', $vans->pluck('id'))->groupBy('warehouse_id')->selectRaw('warehouse_id, SUM(on_hand) AS units')->pluck('units', 'warehouse_id');
        $archive = new CompanyScreen($this->user);
        $manage = VanResource::canCreate();
        $move = Erp::canAnywhere('stock.transfer');

        return ['can_create' => $manage, 'can_move' => $move, 'form' => $manage ? $this->vanForm() : null,
            'rows' => $vans->map(fn (Warehouse $v) => ['id' => $v->id, 'code' => $v->code, 'name' => $v->name, 'reg' => $v->vehicle_reg, 'rep' => $users[$v->rep_user_id] ?? null,
                'home' => $homes[$v->home_warehouse_id] ?? null, 'stock' => number_format((float) ($stock[$v->id] ?? 0), 0).' units', 'can_edit' => $manage,
                'values' => ['branch_id' => $v->branch_id, 'name' => $v->name, 'vehicle_reg' => (string) $v->vehicle_reg, 'rep_user_id' => (string) $v->rep_user_id,
                    'home_warehouse_id' => (string) $v->home_warehouse_id]] + $archive->archiveOf($v))->all()];
    }

    /** What "New van" and "Change" offer: branches, reps, and each branch's warehouses a van can be loaded from. */
    private function vanForm(): array
    {
        return ['branches' => self::choices(Branch::choices(null)), 'reps' => $this->reps(),
            'warehouses' => Warehouse::where('is_van', false)->whereNull('archived_at')->orderBy('code')->get(['id', 'name', 'branch_id'])
                ->map(fn ($w) => ['id' => $w->id, 'label' => $w->name, 'branch_id' => $w->branch_id])->all()];
    }

    // -------------------------------------------------------------------------------------------- Van settlements

    public function settlementsQuery(): Builder
    {
        return VanSettlementResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  status, page, per */
    public function settlements(array $p): array
    {
        $q = $this->settlementsQuery();
        if (isset(VanSettlement::STATUSES[$p['status'] ?? ''])) {
            $q->where('van_settlements.status', $p['status']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();

        return $this->m() + ['total' => $total, 'page' => $page, 'per' => $per, 'statuses' => self::choices(VanSettlement::STATUSES),
            'rows' => $q->orderByDesc('business_date')->orderByDesc('created_at')->forPage($page, $per)->get()->map(fn (VanSettlement $s) => ['id' => $s->id, 'number' => $s->number,
                'day' => $s->business_date?->toDateString(), 'van' => $s->van?->code, 'sales' => (int) $s->sales_minor,
                'cash' => $s->cash_counted_minor === null ? null : (int) $s->cash_counted_minor - (int) $s->cash_expected_minor,
                'stock' => $s->status === 'posted' ? (int) $s->stock_variance_minor : null, 'status' => $s->status,
                'status_label' => VanSettlement::STATUSES[$s->status] ?? $s->status, 'tone' => self::SETTLEMENT_TONE[$s->status] ?? 'subtle'])->all()];
    }

    public function settlement(VanSettlement $s): array
    {
        $lines = $s->lines()->with('product')->get()->sortBy(fn ($l) => $l->product->name)->values();
        $diff = fn ($a, $b) => $a === null ? null : Qty::format(Qty::toMilli((string) $a) - Qty::toMilli((string) $b));

        return $this->m() + ['id' => $s->id, 'number' => $s->number, 'status' => $s->status, 'status_label' => VanSettlement::STATUSES[$s->status] ?? $s->status,
            'tone' => self::SETTLEMENT_TONE[$s->status] ?? 'subtle', 'van' => $s->van?->name, 'reg' => $s->van?->vehicle_reg, 'day' => $s->business_date?->toDateString(),
            'rep' => User::whereKey($s->rep_user_id)->value('name'), 'sales' => (int) $s->sales_minor, 'cash_expected' => $s->cash_expected_minor === null ? null : (int) $s->cash_expected_minor,
            'cash_counted' => $s->cash_counted_minor === null ? null : (int) $s->cash_counted_minor,
            'stock_variance' => $s->status === 'posted' ? (int) $s->stock_variance_minor : null, 'started_by' => $s->started_by_name, 'submitted_by' => $s->submitted_by_name,
            'posted_by' => $s->posted_by_name, 'note' => $s->note,
            'lines' => $lines->map(fn ($l) => ['product_id' => $l->product_id, 'sku' => $l->product->sku, 'name' => $l->product->name, 'expected' => self::qty($l->expected_qty),
                'counted' => self::qty($l->counted_qty), 'difference' => $diff($l->counted_qty, $l->expected_qty), 'unload' => self::qty($l->unload_qty),
                'stays' => $diff($l->counted_qty, $l->unload_qty),
                'values' => ['counted' => $l->counted_qty === null ? '' : rtrim(rtrim((string) $l->counted_qty, '0'), '.'),
                    'unload' => rtrim(rtrim((string) $l->unload_qty, '0'), '.') ?: '0']])->all(),
            'actions' => ['count' => $s->status === 'counting', 'submit' => $s->status === 'counting', 'post' => $s->status === 'submitted',
                'cancel' => in_array($s->status, ['counting', 'submitted'], true)]];
    }

    // -------------------------------------------------------------------------------------------- Routes

    public function routesQuery(): Builder
    {
        return SalesRouteResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  q, show */
    public function routes(array $p): array
    {
        $q = $this->routesQuery()->withCount('stops')->with('stops');
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('routes.code', 'ilike', "%{$s}%")->orWhere('routes.name', 'ilike', "%{$s}%"));
        }
        self::shown($q, $p, 'routes');
        $routes = $q->orderBy('routes.code')->get();
        $users = User::whereIn('id', $routes->pluck('rep_user_id')->filter())->pluck('name', 'id');
        $vans = Warehouse::whereIn('id', $routes->pluck('van_warehouse_id')->filter())->pluck('code', 'id');
        $customers = Customer::whereIn('id', $routes->flatMap(fn ($r) => $r->stops->pluck('customer_id')))->get(['id', 'name', 'code'])->keyBy('id');
        $archive = new CompanyScreen($this->user);
        $manage = SalesRouteResource::canCreate();

        return ['can_create' => $manage, 'days' => self::choices(SalesRoute::DAYS), 'branches' => self::choices(Branch::choices(null)), 'reps' => $this->reps(),
            'vans' => Warehouse::where('is_van', true)->whereNull('archived_at')->orderBy('code')->get(['id', 'name', 'branch_id'])
                ->map(fn ($w) => ['id' => $w->id, 'label' => $w->name, 'branch_id' => $w->branch_id])->all(),
            'rows' => $routes->map(fn (SalesRoute $r) => ['id' => $r->id, 'code' => $r->code, 'name' => $r->name,
                'days' => collect($r->days ?? [])->map(fn ($d) => SalesRoute::DAYS[$d] ?? $d)->implode(' '), 'van' => $vans[$r->van_warehouse_id] ?? null,
                'rep' => $users[$r->rep_user_id] ?? null, 'customers' => (int) $r->stops_count, 'can_edit' => $manage,
                'values' => ['name' => $r->name, 'branch_id' => (string) $r->branch_id, 'van_warehouse_id' => (string) $r->van_warehouse_id, 'rep_user_id' => (string) $r->rep_user_id,
                    'days' => array_map('strval', $r->days ?? []),
                    'stops' => $r->stops->sortBy('sequence')->map(fn ($st) => ['id' => $st->customer_id,
                        'label' => trim(($customers[$st->customer_id]->name ?? '—').' · '.($customers[$st->customer_id]->code ?? ''), ' ·')])->values()->all()]]
                + $archive->archiveOf($r))->all()];
    }

    public function customers(string $q): array
    {
        return Customer::where('is_active', true)->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")->orWhere('code', 'ilike', "%{$q}%"))
            ->orderBy('name')->limit(30)->get()->map(fn ($c) => ['id' => $c->id, 'label' => trim("{$c->name} · {$c->code}", ' ·'), 'detail' => $c->phone])->all();
    }

    // -------------------------------------------------------------------------------------------- Visits

    /** @param  array<string, mixed>  $p  q, outcome, today, page, per */
    public function visits(array $p): array
    {
        $q = VisitResource::getEloquentQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where('user_name', 'ilike', "%{$s}%");
        }
        if (isset(Visit::OUTCOMES[$p['outcome'] ?? ''])) {
            $q->where('outcome', $p['outcome']);
        }
        if (($p['today'] ?? '') === '1') {
            $q->where('occurred_at', '>=', now()->startOfDay());
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('occurred_at')->forPage($page, $per)->get();
        $customers = Customer::whereIn('id', $rows->pluck('customer_id')->filter())->pluck('name', 'id');
        $routes = SalesRoute::whereIn('id', $rows->pluck('route_id')->filter())->pluck('code', 'id');
        $tz = $this->user->tenant?->timezone ?: 'UTC';

        return ['total' => $total, 'page' => $page, 'per' => $per, 'outcomes' => self::choices(Visit::OUTCOMES),
            'rows' => $rows->map(fn (Visit $v) => ['id' => $v->id, 'when' => $v->occurred_at?->timezone($tz)->format('D j M, H:i'), 'rep' => $v->user_name,
                'customer' => $customers[$v->customer_id] ?? null, 'route' => $routes[$v->route_id] ?? null, 'outcome' => Visit::OUTCOMES[$v->outcome] ?? $v->outcome,
                'tone' => ['sold' => 'success', 'order' => 'informative', 'closed' => 'subtle'][$v->outcome] ?? 'warning', 'note' => $v->note,
                'location' => $v->lat === null ? null : sprintf('%.5f, %.5f (±%d m)', $v->lat, $v->lng, (int) $v->accuracy_m),
                'map' => $v->lat === null ? null : "https://www.openstreetmap.org/?mlat={$v->lat}&mlon={$v->lng}#map=17/{$v->lat}/{$v->lng}"])->all()];
    }

    // -------------------------------------------------------------------------------------------- Rep targets

    public function targets(): array
    {
        $rows = RepTarget::query()->orderByDesc('year')->orderByDesc('month')->limit(500)->get();
        $users = User::whereIn('id', $rows->pluck('user_id'))->pluck('name', 'id');
        $e = $this->m()['exponent'];

        return $this->m() + ['reps' => $this->reps(), 'months' => self::choices(collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => now()->setDate(2000, $m, 1)->format('F')])->all()),
            'year' => now()->year, 'month' => now()->month,
            'rows' => $rows->map(fn (RepTarget $t) => ['id' => $t->id, 'rep' => $users[$t->user_id] ?? '—', 'period' => now()->setDate($t->year, $t->month, 1)->format('F Y'),
                'target' => (int) $t->target_minor, 'visits' => $t->visit_target,
                'values' => ['user_id' => $t->user_id, 'year' => (string) $t->year, 'month' => (string) $t->month,
                    'target' => number_format($t->target_minor / (10 ** $e), $e, '.', ''), 'visit_target' => $t->visit_target === null ? '' : (string) $t->visit_target]])->all()];
    }

    // -------------------------------------------------------------------------------------------- Rep performance

    public function performance(?string $from, ?string $to, ?string $date): array
    {
        $ok = fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        $from = $ok($from) ? $from : now()->startOfMonth()->toDateString();
        $to = $ok($to) ? $to : now()->toDateString();
        $date = $ok($date) ? $date : now()->toDateString();
        $tz = Tenant::find($this->user->tenant_id)?->timezone ?: 'UTC';
        $ids = Erp::branchIds('sales.view');

        return $this->m() + ['from' => $from, 'to' => $to, 'date' => $date, 'reps' => DistributionReports::reps($from, $to, $tz, $ids),
            'missed' => collect(DistributionReports::missedVisits($date, $tz, $ids))->map(fn ($m) => ['route' => $m->route, 'sequence' => (int) $m->sequence, 'name' => $m->name,
                'code' => $m->code, 'phone' => $m->phone])->all(),
            'empties' => collect(DistributionReports::empties($ids))->map(fn ($x) => ['customer' => $x->customer, 'code' => $x->code, 'container' => "{$x->sku} · {$x->container}",
                'held' => self::qty($x->held), 'deposit' => (int) $x->deposit_minor])->all()];
    }
}
