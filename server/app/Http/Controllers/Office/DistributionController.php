<?php

namespace App\Http\Controllers\Office;

use App\Domain\Distribution\RouteService;
use App\Domain\Distribution\VanService;
use App\Domain\Distribution\VanSettlementService;
use App\Domain\Office\DistributionScreen;
use App\Domain\Office\StockScreen;
use App\Domain\Org\ArchiveService;
use App\Domain\Support\Actor;
use App\Filament\Resources\SalesRoutes\SalesRouteResource;
use App\Filament\Resources\Vans\VanResource;
use App\Filament\Resources\VanSettlements\VanSettlementResource;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Branch;
use App\Models\SalesRoute;
use App\Support\Exports\Exporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sales & distribution in the new back office (App\Domain\Office\DistributionScreen): vans (new, change, load, unload,
 * start the day's settlement, archive), van settlements (counts, submit, approve and settle, cancel, the sheet), routes
 * (save, archive), visits, rep targets and rep performance — through the services the current pages use.
 */
class DistributionController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $r, bool $can): DistributionScreen
    {
        $this->allowed($can);

        return new DistributionScreen($r->user());
    }

    private static function lines(Request $request): array
    {
        $d = $request->validate(['lines' => ['required', 'array', 'min:1'], 'lines.*.product_uom_id' => ['required', 'uuid'], 'lines.*.qty' => ['required', 'numeric', 'min:0.001']],
            ['lines.*' => 'Add at least one product.', 'lines.*.product_uom_id.*' => 'Choose the product and its unit.', 'lines.*.qty.*' => 'Enter how many.']);

        return array_map(fn ($l) => ['product_uom_id' => $l['product_uom_id'], 'qty' => (string) $l['qty']], $d['lines']);
    }

    // -------------------------------------------------------------------------------------------- Vans

    public function vans(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, DistributionScreen::canVans())->vans($request->query()));
    }

    public function vanSave(Request $request, VanService $vans, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, VanResource::canCreate());
        $d = $request->validate(['branch_id' => [$id ? 'nullable' : 'required', 'uuid'], 'name' => ['required', 'string', 'max:80'], 'vehicle_reg' => ['nullable', 'string', 'max:20'],
            'rep_user_id' => ['nullable', 'uuid'], 'home_warehouse_id' => ['required', 'uuid']],
            ['branch_id.*' => 'Choose the branch.', 'name.*' => 'Name the van.', 'home_warehouse_id.*' => 'Choose the warehouse the van is loaded from.']);
        $d['rep_user_id'] = ($d['rep_user_id'] ?? '') ?: null;
        $d['vehicle_reg'] = ($d['vehicle_reg'] ?? '') ?: null;
        if ($id) {
            $van = $screen->vansQuery()->findOrFail($id);
            unset($d['branch_id']);

            return $this->act(fn (Actor $actor) => ['id' => $vans->update($actor, $van, $d)->id, 'message' => 'Saved.']);
        }
        $branch = Branch::findOrFail($d['branch_id']);

        return $this->act(fn (Actor $actor) => ['id' => $vans->create($actor, $branch, $d)->id, 'message' => 'Van added. Register the rep’s phone as a POS device on its till (System → Devices).']);
    }

    /** load · unload (lines) · settle (start the day's settlement). */
    public function vanAction(Request $request, string $id, VanService $vans, VanSettlementService $settlements): JsonResponse
    {
        $van = $this->screen($request, DistributionScreen::canVans())->vansQuery()->findOrFail($id);
        $this->allowed(Erp::canAnywhere('stock.transfer'));
        $act = (string) $request->input('action');
        if ($act === 'settle') {
            return $this->act(fn (Actor $actor) => ['message' => 'Settlement started.', 'open' => '/office/distribution/settlements/'.$settlements->start($actor, $van)->id]);
        }
        abort_unless(in_array($act, ['load', 'unload'], true), 422, 'Unknown action.');
        $lines = self::lines($request);

        return $this->act(function (Actor $actor) use ($vans, $van, $lines, $act) {
            $vans->load($actor, $van, $lines, $act === 'unload');

            return ['message' => $act === 'unload' ? 'Unloaded.' : 'Van loaded.'];
        });
    }

    public function units(Request $request): JsonResponse
    {
        $this->screen($request, DistributionScreen::canVans());

        return response()->json(['results' => (new StockScreen($request->user()))->units(mb_substr(trim((string) $request->query('q', '')), 0, 60), false)]);
    }

    // -------------------------------------------------------------------------------------------- Van settlements

    public function settlements(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, DistributionScreen::canSettlements())->settlements($request->query()));
    }

    public function settlement(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, DistributionScreen::canSettlements());

        return response()->json($screen->settlement($screen->settlementsQuery()->findOrFail($id)));
    }

    /** count (counted and unload per product) · submit (note) · post (approve and settle) · cancel (reason). */
    public function settlementAction(Request $request, string $id, VanSettlementService $svc): JsonResponse
    {
        $s = $this->screen($request, DistributionScreen::canSettlements())->settlementsQuery()->findOrFail($id);
        switch ((string) $request->input('action')) {
            case 'count':
                $d = $request->validate(['counts' => ['required', 'array'], 'counts.*.counted' => ['nullable', 'numeric', 'min:0'], 'counts.*.unload' => ['nullable', 'numeric', 'min:0']],
                    ['counts.*' => 'Enter the counts.']);
                $counts = collect($d['counts'])->map(fn ($c) => ['counted' => $c['counted'] ?? null, 'unload' => $c['unload'] ?? '0'])->all();

                return $this->act(fn (Actor $actor) => ['message' => $svc->count($actor, $s, $counts) ? 'Counts saved.' : '']);
            case 'submit':
                $note = $request->validate(['note' => ['nullable', 'string', 'max:2000']])['note'] ?? null;

                return $this->act(fn (Actor $actor) => ['message' => $svc->submit($actor, $s, $note ?: null) ? 'Submitted.' : '']);
            case 'post':
                return $this->act(fn (Actor $actor) => ['message' => $svc->post($actor, $s) ? 'Settled.' : '']);
            case 'cancel':
                $reason = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.*' => 'Say why.'])['reason'];

                return $this->act(fn (Actor $actor) => ['message' => $svc->cancel($actor, $s, $reason) ? 'Cancelled.' : '']);
        }
        abort(422, 'Unknown action.');
    }

    /** The settlement sheet, to sign: as the current page's PDF. */
    public function sheet(Request $request, string $id, Exporter $exporter): StreamedResponse
    {
        $s = $this->screen($request, DistributionScreen::canSettlements())->settlementsQuery()->findOrFail($id);
        $t = VanSettlementResource::lineRows($s);
        $sheet = ['title' => "Van settlement {$s->number}", 'headings' => $t['headings'], 'rows' => $t['rows'],
            'note' => sprintf('%s %s · %s · Sales %s · Cash expected %s · handed in %s. Rep: ______________  Storekeeper: ______________  Supervisor: ______________',
                $s->van->code, $s->van->vehicle_reg, $s->business_date->format('j M Y'), FinanceUi::money($s->sales_minor),
                $s->cash_expected_minor === null ? '—' : FinanceUi::money($s->cash_expected_minor), $s->cash_counted_minor === null ? '—' : FinanceUi::money($s->cash_counted_minor))];

        return $exporter->pdf(Actor::user($request->user(), 'web'), $sheet['title'], [$sheet], [], 'portrait');
    }

    // -------------------------------------------------------------------------------------------- Routes

    public function routes(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, DistributionScreen::canRoutes())->routes($request->query()));
    }

    public function customers(Request $request): JsonResponse
    {
        $screen = $this->screen($request, DistributionScreen::canRoutes());

        return response()->json(['results' => $screen->customers(mb_substr(trim((string) $request->query('q', '')), 0, 60))]);
    }

    public function routeSave(Request $request, RouteService $routes, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, SalesRouteResource::canCreate());
        $d = $request->validate(['name' => ['required', 'string', 'max:255'], 'branch_id' => ['required', 'uuid'], 'van_warehouse_id' => ['nullable', 'uuid'], 'rep_user_id' => ['nullable', 'uuid'],
            'days' => ['nullable', 'array'], 'days.*' => ['integer', 'between:1,7'], 'customer_ids' => ['nullable', 'array'], 'customer_ids.*' => ['uuid']],
            ['name.*' => 'Name the route.', 'branch_id.*' => 'Choose the branch.']);
        $r = $id ? $screen->routesQuery()->findOrFail($id) : new SalesRoute;
        $d['van_warehouse_id'] = ($d['van_warehouse_id'] ?? '') ?: null;
        $d['rep_user_id'] = ($d['rep_user_id'] ?? '') ?: null;
        $d['days'] = $d['days'] ?? [];
        $d['customer_ids'] = $d['customer_ids'] ?? [];

        return $this->act(fn (Actor $actor) => ['id' => $routes->save($actor, $r, $d)->id, 'message' => 'Saved.']);
    }

    /** Archive or restore a van or a route. */
    public function archive(Request $request, ArchiveService $svc): JsonResponse
    {
        $d = $request->validate(['kind' => ['required', Rule::in(['van', 'route'])], 'id' => ['required', 'uuid'], 'restore' => ['boolean'],
            'reason' => ['required_unless:restore,true', 'nullable', 'string', 'min:3', 'max:255']], ['reason.*' => 'Say why (at least 3 letters).']);
        $screen = $this->screen($request, $d['kind'] === 'van' ? DistributionScreen::canVans() : DistributionScreen::canRoutes());
        $r = $d['kind'] === 'van' ? $screen->vansQuery()->findOrFail($d['id']) : $screen->routesQuery()->findOrFail($d['id']);
        $this->allowed($svc->allowed($request->user(), $r));

        return $this->act(function (Actor $actor) use ($svc, $r, $d) {
            ($d['restore'] ?? false) ? $svc->restore($actor, $r) : $svc->archive($actor, $r, $d['reason']);

            return ['message' => ($d['restore'] ?? false) ? 'Restored.' : 'Archived.'];
        });
    }

    // -------------------------------------------------------------------------------------------- Visits, targets, performance

    public function visits(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, DistributionScreen::canVisits())->visits($request->query()));
    }

    public function targets(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, DistributionScreen::canTargets())->targets());
    }

    public function targetSave(Request $request, RouteService $routes): JsonResponse
    {
        $this->screen($request, DistributionScreen::canTargets());
        $d = $request->validate(['user_id' => ['required', 'uuid'], 'year' => ['required', 'integer', 'min:2000', 'max:2100'], 'month' => ['required', 'integer', 'between:1,12'],
            'target' => ['required', 'numeric', 'min:0'], 'visit_target' => ['nullable', 'integer', 'min:0']],
            ['user_id.*' => 'Choose the rep.', 'month.*' => 'Choose the month.', 'target.*' => 'Enter the sales target.']);

        return $this->act(function (Actor $actor) use ($routes, $d) {
            $routes->setTarget($actor, $d);

            return ['message' => 'Target saved.'];
        });
    }

    public function performance(Request $request): JsonResponse
    {
        $screen = $this->screen($request, DistributionScreen::canPerformance());
        $q = fn (string $k) => is_string($v = $request->query($k)) ? $v : null;

        return response()->json($screen->performance($q('from'), $q('to'), $q('date')));
    }
}
