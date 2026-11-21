<?php

namespace App\Http\Controllers\Office;

use App\Domain\Office\BatchesScreen;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Support\Erp;
use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Batches & expiry in the new back office (App\Domain\Office\BatchesScreen): the store's batches, markdowns (apply,
 * withdraw), Correct expiry and Add or move a batch, each through its domain service (permission checked there too).
 */
class BatchesController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->allowed($request);

        return response()->json(($this->screen($request))->show($request->query('warehouse')));
    }

    public function products(Request $request): JsonResponse
    {
        $this->allowed($request);

        return response()->json(['products' => ($this->screen($request))->products((string) $request->query('q', ''))]);
    }

    public function productBatches(Request $request): JsonResponse
    {
        $this->allowed($request);
        $d = $request->validate(['warehouse' => ['required', 'uuid'], 'product' => ['required', 'uuid']]);
        $this->mine($d['warehouse']);

        return response()->json(['batches' => ($this->screen($request))->productBatches($d['warehouse'], $d['product'])]);
    }

    public function apply(Request $request): JsonResponse
    {
        $this->allowed($request);
        $d = $request->validate(['warehouse' => ['required', 'uuid'], 'batch' => ['required', 'uuid'], 'percent' => ['required', 'numeric']], ['percent.required' => 'Type the % off.']);
        $this->mine($d['warehouse']);

        return $this->act($request, fn ($screen, $actor) => ['message' => 'Markdown '.$screen->apply($actor, $d['warehouse'], $d['batch'], (string) $d['percent'])->code.' applied: print the stickers.']);
    }

    public function withdraw(Request $request, string $markdown): JsonResponse
    {
        $this->allowed($request);

        return $this->act($request, fn ($screen, $actor) => ['message' => 'Markdown '.$screen->withdraw($actor, $markdown)->code.' withdrawn.']);
    }

    public function correct(Request $request): JsonResponse
    {
        $this->allowed($request);
        $d = $request->validate(['batch' => ['required', 'uuid'], 'expires_on' => ['required', 'date'], 'reason' => ['required', 'string', 'max:255']],
            ['expires_on.required' => 'Choose the expiry date.', 'reason.required' => 'Say why.']);

        return $this->act($request, fn ($screen, $actor) => ['message' => 'Batch '.$screen->correct($actor, $d['batch'], $d['expires_on'], $d['reason'])->batch_no.': expiry date corrected.']);
    }

    /** Add or move a batch: a draft stock adjustment, opened next for checking and posting. */
    public function addBatch(Request $request): JsonResponse
    {
        $this->allowed($request);
        $d = $request->validate([
            'warehouse' => ['required', 'uuid'], 'kind' => ['required', 'in:found,move'], 'product_id' => ['required', 'uuid'],
            'from_batch_id' => ['required_if:kind,move', 'nullable', 'uuid'], 'batch_no' => ['required', 'string', 'max:60'], 'expires_on' => ['required', 'date'],
            'qty' => ['required', 'numeric', 'gt:0'], 'unit_cost' => ['nullable', 'numeric', 'min:0'], 'note' => ['nullable', 'string', 'max:255'],
        ], ['product_id.required' => 'Choose the product.', 'from_batch_id.required_if' => 'Choose the batch to take it from.', 'batch_no.required' => 'Type the batch number.',
            'expires_on.required' => 'Choose the expiry date.', 'qty.gt' => 'Type the quantity.']);
        $w = $this->mine($d['warehouse']);
        abort_unless(Erp::can('stock.adjust', $w), 403, 'You cannot adjust stock in this store.');

        return $this->act($request, function ($screen, $actor) use ($d, $w) {
            $a = $screen->addBatch($actor, $w->id, $d);

            return ['adjustment' => ['id' => $a->id, 'number' => $a->number, 'open' => "/office/adjustments/{$a->id}"]];
        });
    }

    /** A store this person may see stock in (the address or a changed request cannot reach another branch's batches). */
    private function mine(string $warehouse): Warehouse
    {
        $ids = Erp::branchIds('stock.view');
        $w = Warehouse::findOrFail($warehouse);
        abort_unless($ids === null || in_array($w->branch_id, $ids, true), 403, 'You cannot see this store.');

        return $w;
    }

    private function act(Request $request, callable $fn): JsonResponse
    {
        $screen = $this->screen($request);
        try {
            return response()->json(DB::transaction(fn () => $fn($screen, Actor::user($request->user(), 'web'))));
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage() ?: 'You may not do this here.'], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
    }

    /** Batches & expiry, or an industry's markdowns page (?industry=grocery|pharmacy), as the person may open it. */
    private function kind(Request $request): ?string
    {
        $kind = (string) $request->input('industry', '');

        return array_key_exists($kind, BatchesScreen::KINDS) ? $kind : null;
    }

    private function screen(Request $request): BatchesScreen
    {
        return new BatchesScreen($request->user(), $this->kind($request));
    }

    private function allowed(Request $request): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(BatchesScreen::canView($this->kind($request)), 403, 'You cannot see batches.');
    }
}
