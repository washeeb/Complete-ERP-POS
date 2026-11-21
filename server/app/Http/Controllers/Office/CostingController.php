<?php

namespace App\Http\Controllers\Office;

use App\Domain\Inventory\StockCostRuns;
use App\Domain\Office\CostingScreen;
use App\Domain\Purchasing\LandedCostService;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Resources\StockCostRuns\StockCostRunResource;
use App\Filament\Support\FinanceUi;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\LandedCost;
use App\Models\StockCostRun;
use App\Support\Exports\Exporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The last of Stock and Purchasing in the new back office (App\Domain\Office\CostingScreen): Landed costs (the list,
 * per product, Spread freight and duty), Cost runs (the list, the form, the preview and its buttons), Estimated against
 * real costs and Spend analytics (with its export) — each through the same service as the current pages.
 */
class CostingController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $r, bool $can): CostingScreen
    {
        $this->allowed($can);

        return new CostingScreen($r->user());
    }

    // -------------------------------------------------------------------------------------------- Landed costs

    public function landed(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, CostingScreen::canLanded())->landed($request->query()));
    }

    public function landedShow(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, CostingScreen::canLanded());

        return response()->json($screen->landedLines($screen->landedQuery()->findOrFail($id)));
    }

    public function spreadForm(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, CostingScreen::canSpread())->spreadForm());
    }

    public function spread(Request $request, LandedCostService $svc): JsonResponse
    {
        $this->screen($request, CostingScreen::canSpread());
        $d = $request->validate(['receipts' => ['required', 'array', 'min:1'], 'receipts.*' => ['uuid'], 'method' => ['required', Rule::in(array_keys(LandedCost::METHODS))],
            'charges' => ['required', 'array', 'min:1', 'max:50'], 'charges.*.kind' => ['required', Rule::in(array_keys(LandedCost::KINDS))],
            'charges.*.description' => ['required', 'string', 'max:255'], 'charges.*.amount' => ['required', 'numeric'], 'note' => ['nullable', 'string', 'max:255']],
            ['receipts.*' => 'Choose the deliveries the charges belong to.', 'charges.required' => 'Add at least one charge.',
                'charges.*.description.*' => 'Describe every charge.', 'charges.*.amount.*' => 'Enter the amount of every charge.']);

        return $this->act(function (Actor $actor) use ($svc, $d) {
            $lc = $svc->post($actor, $d['receipts'], array_map(fn ($c) => ['kind' => $c['kind'], 'description' => trim($c['description']),
                'amount_minor' => FinanceUi::toMinor($c['amount'])], $d['charges']), $d['method'], ($d['note'] ?? '') ?: null);

            return ['id' => $lc->id, 'message' => "Landed cost {$lc->number} posted."];
        });
    }

    // -------------------------------------------------------------------------------------------- Cost runs

    public function runs(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, CostingScreen::canRuns())->runs($request->query()));
    }

    public function runShow(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, CostingScreen::canRuns());

        return response()->json($screen->run($screen->runsQuery()->findOrFail($id), $request->query()));
    }

    public function runForm(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, StockCostRunResource::canCreate())->runForm());
    }

    public function runProducts(Request $request): JsonResponse
    {
        $screen = $this->screen($request, StockCostRunResource::canCreate());

        return response()->json(['results' => $screen->products(mb_substr(trim((string) $request->query('q', '')), 0, 60))]);
    }

    public function runSave(Request $request, StockCostRuns $svc): JsonResponse
    {
        $this->screen($request, StockCostRunResource::canCreate());
        $ids = ['nullable', 'array', 'max:5000'];
        $d = $request->validate(['method' => ['required', Rule::in(array_keys(StockCostRun::METHODS))], 'value' => ['required', 'numeric'],
            'price_list_id' => ['required', 'uuid'], 'tax_mode' => ['required', Rule::in(array_keys(StockCostRun::TAX_MODES))],
            'overrides' => ['nullable', 'array', 'max:100'], 'overrides.*.kind' => ['required', 'in:category,brand'], 'overrides.*.id' => ['required', 'uuid'],
            'overrides.*.value' => ['required', 'numeric'], 'product_ids' => $ids, 'product_ids.*' => ['uuid'], 'category_ids' => $ids, 'category_ids.*' => ['uuid'],
            'brand_ids' => $ids, 'brand_ids.*' => ['uuid'], 'supplier_ids' => $ids, 'supplier_ids.*' => ['uuid'], 'warehouse_ids' => $ids, 'warehouse_ids.*' => ['uuid'],
            'overwrite' => ['boolean'], 'catch_up' => ['boolean'], 'effective_on' => ['required', 'date_format:Y-m-d'], 'account_id' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:255']],
            ['method.*' => 'Choose how the cost is worked out.', 'value.*' => 'Enter the factor or percentage.', 'price_list_id.*' => 'Choose the price list to work from.',
                'overrides.*' => 'Choose the category or brand and its factor on every different factor.', 'effective_on.*' => 'Choose the date in the books.']);
        foreach (['product_ids', 'category_ids', 'brand_ids', 'supplier_ids', 'warehouse_ids'] as $k) {
            $d[$k] = array_values(array_unique($d[$k] ?? []));
        }
        $d['account_id'] = ($d['account_id'] ?? '') ?: null;

        return $this->act(fn (Actor $actor) => ['id' => $svc->prepare($actor, $d)->id]);
    }

    /** recalculate · replace · submit · post · cancel (reason) · undo (reason), as the current page's buttons. */
    public function runAction(Request $request, string $id, StockCostRuns $svc): JsonResponse
    {
        $screen = $this->screen($request, CostingScreen::canRuns());
        /** @var StockCostRun $run */
        $run = $screen->runsQuery()->findOrFail($id);
        $a = $request->validate(['action' => ['required', Rule::in(['recalculate', 'replace', 'submit', 'post', 'cancel', 'undo'])]])['action'];
        $reason = in_array($a, ['cancel', 'undo'], true) ? $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.*' => 'Give the reason.'])['reason'] : '';
        if ($a === 'post') {
            @set_time_limit(0); // a whole catalogue, in one transaction
        }

        return $this->act(function (Actor $actor) use ($a, $run, $reason, $svc, $screen) {
            $done = match ($a) {
                'recalculate' => [$svc->recalculate($actor, $run), 'Worked out again.'],
                'replace' => [$svc->replaceExisting($actor, $run), 'Worked out again, replacing their costs.'],
                'submit' => [$svc->submit($actor, $run), 'Sent for approval.'],
                'post' => [$svc->post($actor, $run), 'Cost run posted.'],
                'cancel' => [$svc->cancel($actor, $run, $reason), 'Cancelled.'],
                default => [$svc->undo($actor, $run, $reason), 'Cost run undone.'],
            };

            return ['message' => $done[1], 'run' => $screen->run($done[0]->fresh())];
        });
    }

    // -------------------------------------------------------------------------------------------- Estimated costs, spend

    public function estimates(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, CostingScreen::canEstimates())->estimates($request->query()));
    }

    public function spend(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, CostingScreen::canSpend())->spend($request->query()));
    }

    public function spendExport(Request $request, Exporter $exporter): StreamedResponse|JsonResponse
    {
        $x = $this->screen($request, CostingScreen::canSpend())->spendSheets($request->query());
        $format = (string) $request->query('format', 'xlsx');
        abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 422, 'Choose Excel, CSV or PDF.');
        $actor = Actor::user($request->user(), 'web');
        try {
            return match ($format) {
                'pdf' => $exporter->pdf($actor, $x['title'], $x['sheets'], $x['filters']),
                'xlsx' => $exporter->xlsx($actor, $x['title'], $x['sheets'], $x['filters']),
                default => $exporter->csv($actor, $x['title'], $x['sheets'], $x['filters']),
            };
        } catch (DomainException $e) { // e.g. a licence that does not allow output
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
    }
}
