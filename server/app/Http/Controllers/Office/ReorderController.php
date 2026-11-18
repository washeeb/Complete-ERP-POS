<?php

namespace App\Http\Controllers\Office;

use App\Domain\Office\ReorderScreen;
use App\Domain\Purchasing\ReorderService;
use App\Domain\Purchasing\SmartReorderLevels;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Resources\ReorderRules\ReorderRuleResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Warehouse;
use App\Support\Qty;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reorder levels, What to order and Suggested levels in the new back office (App\Domain\Office\ReorderScreen), through
 * ReorderService and SmartReorderLevels as the current screens.
 */
class ReorderController extends Controller
{
    // ---- Reorder levels --------------------------------------------------------------------------------------------

    public function rules(Request $request): JsonResponse
    {
        $this->panel();
        abort_unless(ReorderScreen::canRules(), 403, 'You cannot see reorder levels.');

        return response()->json((new ReorderScreen($request->user()))->rules($request->query()));
    }

    public function rulesOptions(Request $request): JsonResponse
    {
        $this->panel();
        abort_unless(ReorderScreen::canRules(), 403);

        return response()->json((new ReorderScreen($request->user()))->rulesOptions());
    }

    public function products(Request $request): JsonResponse
    {
        $this->panel();
        abort_unless(ReorderScreen::canSetRules(), 403);

        return response()->json(['results' => (new ReorderScreen($request->user()))->products(mb_substr(trim((string) $request->query('q', '')), 0, 60))]);
    }

    public function productUnits(Request $request): JsonResponse
    {
        $this->panel();
        abort_unless(ReorderScreen::canSetRules(), 403);
        $p = Product::findOrFail((string) $request->query('product'));

        return response()->json((new ReorderScreen($request->user()))->productUnits($p->id));
    }

    public function ruleForm(Request $request, string $id): JsonResponse
    {
        $this->panel();
        $rule = ReorderRuleResource::getEloquentQuery()->findOrFail($id);
        abort_unless(ReorderRuleResource::canEdit($rule), 403, 'You cannot change levels in this store.');

        return response()->json((new ReorderScreen($request->user()))->ruleForm($rule));
    }

    /** Own figure for a product (one store or every store), or a change: figures in a unit, kept in base units. */
    public function saveRule(Request $request, ReorderService $reorder): JsonResponse
    {
        $this->panel();
        abort_unless(ReorderScreen::canSetRules(), 403, 'You cannot set reorder levels.');
        $d = $request->validate(['warehouse_id' => ['required', 'string'], 'product_id' => ['required', 'uuid'], 'product_uom_id' => ['required', 'uuid'],
            'min' => ['required', 'numeric', 'min:0'], 'max' => ['required', 'numeric', 'gt:0']],
            ['max.gt' => 'The maximum must be above zero.', 'product_id.required' => 'Choose the product.']);
        $unit = ProductUom::where('product_id', $d['product_id'])->findOrFail($d['product_uom_id']);
        $factor = Qty::factorMicro((string) $unit->factor);
        $base = fn ($v) => Qty::format((int) round(Qty::toMilli((string) $v) * $factor / 1_000_000));
        $product = Product::findOrFail($d['product_id']);
        $mine = ReorderScreen::canSetRules() ? Fields::warehouses(['warehouse.manage', 'stock.levels']) : [];
        abort_unless($d['warehouse_id'] === 'all' || isset($mine[$d['warehouse_id']]), 403, 'You cannot set levels in that store.');
        $warehouses = $d['warehouse_id'] === 'all' ? Warehouse::whereIn('id', array_keys($mine))->get() : collect([Warehouse::findOrFail($d['warehouse_id'])]);

        return $this->act($request, function (Actor $actor) use ($reorder, $warehouses, $product, $base, $d) {
            $warehouses->each(fn ($w) => $reorder->saveRule($actor, $w, $product, $base($d['min']), $base($d['max'])));

            return ['saved' => $warehouses->count()];
        });
    }

    public function useDefault(Request $request, string $id, ReorderService $reorder): JsonResponse
    {
        $this->panel();
        $rule = ReorderRuleResource::getEloquentQuery()->findOrFail($id);
        abort_unless(ReorderRuleResource::canEdit($rule), 403, 'You cannot change levels in this store.');

        return $this->act($request, fn (Actor $actor) => ['done' => (bool) $reorder->useDefault($actor, $rule)]);
    }

    // ---- What to order ---------------------------------------------------------------------------------------------

    public function orderOptions(Request $request): JsonResponse
    {
        $this->order();

        return response()->json((new ReorderScreen($request->user()))->orderOptions());
    }

    /** The lines to order, with the buyer's changes applied (sent back each time; they stay on the page until ordering). */
    public function suggestions(Request $request): JsonResponse
    {
        $this->order();
        $d = $request->validate($this->changeRules() + ['warehouse' => ['nullable', 'uuid'], 'supplier' => ['nullable', 'string']]);
        $screen = new ReorderScreen($request->user());
        $wh = $screen->orderWarehouse($d['warehouse'] ?? null) ?? abort(403, 'You cannot order for any store.');

        return $this->act($request, fn () => $screen->suggestions($wh, $d['supplier'] ?? null, $d['changes'] ?? []));
    }

    public function lineChoices(Request $request): JsonResponse
    {
        $this->order();

        return response()->json((new ReorderScreen($request->user()))->lineChoices(Product::findOrFail((string) $request->query('product'))->id));
    }

    /** A product's levels in this store, from a line: saved now, so the next suggestion follows them. */
    public function saveLevels(Request $request, ReorderService $reorder): JsonResponse
    {
        $this->order();
        $d = $request->validate(['warehouse' => ['required', 'uuid'], 'product' => ['required', 'uuid'], 'min' => ['required', 'numeric', 'min:0'], 'max' => ['required', 'numeric', 'gt:0']]);
        $wh = Warehouse::findOrFail($d['warehouse']);
        abort_unless(Erp::can('stock.levels', $wh) || Erp::can('warehouse.manage', $wh), 403, 'You cannot change levels in this store.');

        return $this->act($request, fn (Actor $actor) => ['id' => $reorder->saveRule($actor, $wh, Product::findOrFail($d['product']), (string) $d['min'], (string) $d['max'])->id]);
    }

    /** Ask other stores to send: one stock request to each store with stock to spare, for the ticked lines. */
    public function requestMoves(Request $request, ReorderService $reorder): JsonResponse
    {
        $this->order();
        $d = $request->validate(['warehouse' => ['required', 'uuid'], 'products' => ['required', 'array', 'min:1'], 'products.*' => ['uuid']]);
        $wh = (new ReorderScreen($request->user()))->orderWarehouse($d['warehouse']);
        abort_unless($wh && $wh->id === $d['warehouse'], 403);

        return $this->act($request, fn (Actor $actor) => ['requests' => collect($reorder->requestMoves($actor, $wh, $d['products']))->pluck('number')->values()->all()]);
    }

    /** One draft purchase order per supplier from the ticked lines, with the buyer's changes. */
    public function createDrafts(Request $request, ReorderService $reorder): JsonResponse
    {
        $this->order();
        $d = $request->validate($this->changeRules() + ['warehouse' => ['required', 'uuid'], 'products' => ['required', 'array', 'min:1'], 'products.*' => ['uuid']],
            ['products.required' => 'Tick the lines to order.']);
        $wh = (new ReorderScreen($request->user()))->orderWarehouse($d['warehouse']);
        abort_unless($wh && $wh->id === $d['warehouse'], 403);

        return $this->act($request, fn (Actor $actor) => ['orders' => collect($reorder->createDrafts($actor, $wh, $d['products'], $d['changes'] ?? []))
            ->map(fn ($po) => ['id' => $po->id, 'number' => $po->number])->values()->all()]);
    }

    // ---- Suggested levels -------------------------------------------------------------------------------------------

    public function suggested(Request $request): JsonResponse
    {
        $this->suggestedAllowed();

        return response()->json((new ReorderScreen($request->user()))->suggested($request->query('warehouse')));
    }

    public function recalculate(Request $request, SmartReorderLevels $smart): JsonResponse
    {
        $this->suggestedAllowed();
        $n = 0;
        foreach (Warehouse::whereIn('id', array_keys(Fields::warehouses(['warehouse.manage', 'stock.levels'])))->get() as $w) {
            $n += $smart->recalculate($w)['suggested'];
        }

        return response()->json(['suggested' => $n]);
    }

    public function accept(Request $request, SmartReorderLevels $smart): JsonResponse
    {
        $this->suggestedAllowed();
        $d = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['uuid']], ['ids.required' => 'Tick the levels to accept.']);
        $mine = array_keys(Fields::warehouses(['warehouse.manage', 'stock.levels']));
        $ids = DB::table('reorder_level_suggestions')->whereIn('id', $d['ids'])->whereIn('warehouse_id', $mine)->pluck('id')->all(); // only stores the person sets levels for

        return $this->act($request, fn (Actor $actor) => ['accepted' => $smart->accept($actor, $ids)]);
    }

    /** @return array<string, list<string>> */
    private function changeRules(): array
    {
        return ['changes' => ['nullable', 'array'], 'changes.*.supplier_id' => ['nullable', 'uuid'], 'changes.*.product_uom_id' => ['nullable', 'uuid'],
            'changes.*.qty' => ['nullable', 'numeric', 'gt:0'], 'changes.*.cost' => ['nullable', 'numeric', 'min:0']];
    }

    private function act(Request $request, Closure $fn): JsonResponse
    {
        $actor = Actor::user($request->user(), 'web');
        try {
            return response()->json(DB::transaction(fn () => $fn($actor)));
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage() ?: 'You may not do this here.'], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
    }

    private function panel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function order(): void
    {
        $this->panel();
        abort_unless(ReorderScreen::canOrder(), 403, 'You cannot see what to order.');
    }

    private function suggestedAllowed(): void
    {
        $this->panel();
        abort_unless(ReorderScreen::canSuggested(), 403, 'You cannot see suggested levels (or smart levels are off).');
    }
}
