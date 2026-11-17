<?php

namespace App\Http\Controllers\Office;

use App\Domain\Inventory\StockAdjustmentService;
use App\Domain\Office\AdjustmentsScreen;
use App\Domain\Office\StockScreen;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Http\Controllers\Controller;
use App\Models\StockAdjustment;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Stock adjustments in the new back office (App\Domain\Office\AdjustmentsScreen), through StockAdjustmentService. */
class AdjustmentsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new AdjustmentsScreen($request->user()))->list($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new AdjustmentsScreen($request->user()))->options());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->allowed();

        return response()->json((new AdjustmentsScreen($request->user()))->show($this->find($id)));
    }

    public function form(Request $request, ?string $id = null): JsonResponse
    {
        $this->allowed();
        $a = $id ? $this->find($id) : null;
        abort_unless($a ? StockAdjustmentResource::canEdit($a) : StockAdjustmentResource::canCreate(), 403, $a ? 'Only a draft can be changed.' : 'You cannot adjust stock.');

        return response()->json((new AdjustmentsScreen($request->user()))->form($a));
    }

    /** Save a new adjustment or a draft: the header and every line, in one go. */
    public function save(Request $request, ?string $id = null): JsonResponse
    {
        $this->allowed();
        $a = $id ? $this->find($id) : new StockAdjustment;
        $request->validate([
            'values' => ['required', 'array'], 'values.warehouse_id' => [$id ? 'nullable' : 'required', 'uuid'], 'values.reason_code' => ['required', 'string', 'max:40'],
            'values.reference' => ['nullable', 'string', 'max:60'], 'values.note' => ['nullable', 'string', 'max:2000'],
            'values.lines' => ['required', 'array', 'min:1', 'max:500'], 'values.lines.*.product_uom_id' => ['required', 'uuid'], 'values.lines.*.qty' => ['required'],
        ], ['values.lines.required' => 'Add at least one line.', 'values.lines.min' => 'Add at least one line.', 'values.lines.*.product_uom_id.required' => 'Choose the product on every line.']);

        return $this->act($request, fn (Actor $actor) => ['id' => (new AdjustmentsScreen($request->user()))->save($actor, $a, (array) $request->input('values'))->id]);
    }

    /** Submit for approval, approve and post, send back to draft, or cancel (with a reason). */
    public function action(Request $request, string $id, StockAdjustmentService $svc): JsonResponse
    {
        $this->allowed();
        $d = $request->validate(['action' => ['required', Rule::in(['submit', 'post', 'reopen', 'cancel'])],
            'reason' => ['required_if:action,reopen,cancel', 'nullable', 'string', 'max:255']]);
        $a = $this->find($id);

        return $this->act($request, function (Actor $actor) use ($svc, $a, $d) {
            match ($d['action']) {
                'submit' => $svc->submit($actor, $a),
                'post' => $svc->post($actor, $a),
                'reopen' => $svc->reopen($actor, $a, $d['reason']),
                default => $svc->cancel($actor, $a, $d['reason']),
            };

            return (new AdjustmentsScreen($actor->user))->show($a->fresh());
        });
    }

    /** The adjustment voucher (PDF, to sign) or its lines (CSV), as on the current screen. */
    public function voucher(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $this->allowed();
        $format = $request->query('format') === 'csv' ? 'csv' : 'pdf';

        return StockAdjustmentResource::voucher($this->find($id), $format) // null: refused (e.g. a licence without output)
            ?? response()->json(['error' => 'refused', 'detail' => 'The voucher could not be made. Check the licence allows printing and exports.'], 422);
    }

    /** A photo of the goods for a line (damaged, expired…): kept privately, then named on the line when the draft is saved. */
    public function photo(Request $request): JsonResponse
    {
        $this->allowed();
        abort_unless(StockAdjustmentResource::canCreate(), 403, 'You cannot adjust stock.');
        $request->validate(['photo' => ['required', 'image', 'max:5120']]);

        return response()->json(['path' => $request->file('photo')->store('stock-photos', 'local')]);
    }

    /** Product units for a line, and what the warehouse holds of one. */
    public function units(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json(['results' => (new StockScreen($request->user()))->units(mb_substr(trim((string) $request->query('q', '')), 0, 60), $request->boolean('buying'))]);
    }

    public function unitStock(Request $request): JsonResponse
    {
        $this->allowed();
        $d = $request->validate(['unit' => ['required', 'uuid'], 'warehouse' => ['required', 'uuid'], 'batch' => ['nullable', 'string', 'max:60']]);

        return response()->json(['stock' => (new StockScreen($request->user()))->unitStock($d['unit'], $d['warehouse'], $d['batch'] ?? null)]);
    }

    private function find(string $id): StockAdjustment
    {
        return StockAdjustmentResource::getEloquentQuery()->findOrFail($id);
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

    private function allowed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(AdjustmentsScreen::canView(), 403, 'You cannot see stock adjustments.');
    }
}
