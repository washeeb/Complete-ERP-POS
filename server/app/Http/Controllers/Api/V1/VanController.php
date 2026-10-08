<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Distribution\RouteService;
use App\Domain\Modules\ModuleService;
use App\Domain\Support\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Device;
use App\Models\RepTarget;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Distribution on the rep's phone (the van's till): today's route and target, visits and orders taken on the road. */
class VanController extends Controller
{
    public function __construct(private readonly RouteService $routes) {}

    public function show(Request $request): JsonResponse
    {
        $till = Till::findOrFail($this->device($request)->till_id);
        $this->module($till);

        return response()->json(['van' => self::today($till, $request->user())]);
    }

    public function visit(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['required', 'uuid'], 'customer_id' => ['required', 'uuid'], 'route_id' => ['nullable', 'uuid'],
            'outcome' => ['required', 'string', 'max:12'], 'note' => ['nullable', 'string', 'max:255'], 'lat' => ['nullable', 'numeric'], 'lng' => ['nullable', 'numeric'],
            'accuracy_m' => ['nullable', 'integer', 'min:0'], 'sale_id' => ['nullable', 'uuid'], 'customer_order_id' => ['nullable', 'uuid'], 'occurred_at' => ['required', 'date']]);
        $this->module(Till::findOrFail($this->device($request)->till_id));

        return response()->json($this->routes->visit($this->device($request), $request->user(), $data));
    }

    public function order(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['required', 'uuid'], 'customer_id' => ['required', 'uuid'], 'note' => ['nullable', 'string', 'max:1000'],
            'customer_ref' => ['nullable', 'string', 'max:60'], 'lines' => ['required', 'array', 'min:1', 'max:200'], 'lines.*.product_uom_id' => ['required', 'uuid'],
            'lines.*.qty' => ['required', 'string', 'max:20']]);
        $this->module(Till::findOrFail($this->device($request)->till_id));
        $r = $this->routes->takeOrder($this->device($request), $request->user(), $data);

        return response()->json(['status' => $r['status'], 'order' => ['id' => $r['order']->id, 'number' => $r['order']->number, 'status' => $r['order']->status,
            'total_minor' => $r['order']->total_minor, 'hold_reason' => $r['order']->hold_reason]], $r['status'] === 'accepted' ? 201 : 200);
    }

    /** Today's route, the stops in order, and the rep's month: target, sold so far (before tax) and visits. */
    public static function today(Till $till, ?User $user): ?array
    {
        $day = RouteService::today($till);
        if (! $day) {
            return null;
        }
        $tz = Branch::find($till->branch_id)?->timezone ?: (Tenant::find($till->tenant_id)?->timezone ?: 'UTC');
        $start = Carbon::now($tz)->startOfMonth();
        $target = $user ? RepTarget::where('user_id', $user->id)->where('year', $start->year)->where('month', $start->month)->first() : null;
        $sold = $user ? (int) DB::table('sales')->where('till_id', $till->id)->where('cashier_id', $user->id)->where('status', 'posted')
            ->where('occurred_at', '>=', $start->copy()->utc())->sum('net_minor') : 0;

        return $day + ['month' => ['target_minor' => $target?->target_minor, 'sold_minor' => $sold, 'visit_target' => $target?->visit_target]];
    }

    private function module(Till $till): void
    {
        if (! app(ModuleService::class)->isEnabled(Branch::findOrFail($till->branch_id), 'distribution')) {
            throw new DomainException('Sales & Distribution is not on at this branch.', 'module_disabled');
        }
    }

    private function device(Request $request): Device
    {
        return $request->attributes->get('device');
    }
}
