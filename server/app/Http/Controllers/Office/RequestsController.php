<?php

namespace App\Http\Controllers\Office;

use App\Domain\Inventory\StockRequestService;
use App\Domain\Office\RequestsScreen;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Resources\StockRequests\StockRequestResource;
use App\Http\Controllers\Controller;
use App\Models\StockRequest;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Stock requests in the new back office (App\Domain\Office\RequestsScreen), through StockRequestService. */
class RequestsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new RequestsScreen($request->user()))->list($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new RequestsScreen($request->user()))->options());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->allowed();

        return response()->json((new RequestsScreen($request->user()))->show($this->find($id)));
    }

    public function form(Request $request): JsonResponse
    {
        $this->allowed();
        abort_unless(StockRequestResource::canCreate(), 403, 'You cannot ask for stock.');

        return response()->json((new RequestsScreen($request->user()))->form());
    }

    /** "You have" / "They have" for a line of the form. */
    public function stock(Request $request): JsonResponse
    {
        $this->allowed();
        $d = $request->validate(['unit' => ['required', 'uuid'], 'for' => ['nullable', 'uuid'], 'from' => ['nullable', 'uuid']]);

        return response()->json((new RequestsScreen($request->user()))->stock($d['unit'], $d['for'] ?? null, $d['from'] ?? null));
    }

    /** Between industries, the supplier's own products to send for a line (only to someone who may send this request). */
    public function products(Request $request, string $id): JsonResponse
    {
        $this->allowed();
        $screen = new RequestsScreen($request->user());
        $r = $this->find($id);
        abort_unless($screen->show($r)['actions']['supply'], 403, 'You cannot send this request.');

        return response()->json($screen->ownProducts($r, (string) $request->query('q', '')));
    }

    public function save(Request $request): JsonResponse
    {
        $this->allowed();
        $request->validate([
            'values' => ['required', 'array'], 'values.to_warehouse_id' => ['required', 'uuid'], 'values.from_warehouse_id' => ['required', 'uuid', 'different:values.to_warehouse_id'],
            'values.note' => ['nullable', 'string', 'max:2000'], 'values.lines' => ['required', 'array', 'min:1', 'max:500'],
            'values.lines.*.product_uom_id' => ['required', 'uuid'], 'values.lines.*.qty' => ['required', 'numeric', 'gt:0'],
        ], ['values.from_warehouse_id.different' => 'Ask a different warehouse from your own.', 'values.lines.required' => 'Add at least one line.',
            'values.lines.*.product_uom_id.required' => 'Choose the product on every line.', 'values.lines.*.qty.gt' => 'Enter a quantity above zero on every line.']);

        return $this->act($request, fn (Actor $actor) => ['id' => (new RequestsScreen($request->user()))->request($actor, (array) $request->input('values'))->id]);
    }

    /** Approve, send (with what is sent per line and, between industries, the supplier's own product), reject or cancel (with a reason). */
    public function action(Request $request, string $id, StockRequestService $svc): JsonResponse
    {
        $this->allowed();
        $d = $request->validate([
            'action' => ['required', Rule::in(['approve', 'supply', 'reject', 'cancel'])],
            'reason' => ['required_if:action,reject', 'required_if:action,cancel', 'nullable', 'string', 'max:255'],
            'lines' => ['required_if:action,supply', 'array'], 'lines.*.qty' => ['required', 'numeric', 'min:0'], 'lines.*.send_product_id' => ['nullable', 'uuid'],
        ]);
        $r = $this->find($id);

        return $this->act($request, function (Actor $actor) use ($svc, $r, $d) {
            match ($d['action']) {
                'approve' => $svc->approve($actor, $r),
                'supply' => $svc->supply($actor, $r, collect($d['lines'])->map(fn ($l) => ['qty' => (string) $l['qty'], 'send_product_id' => $l['send_product_id'] ?? null])->all()),
                'reject' => $svc->reject($actor, $r, $d['reason']),
                default => $svc->cancel($actor, $r, $d['reason']),
            };

            return (new RequestsScreen($actor->user))->show($r->fresh());
        });
    }

    private function find(string $id): StockRequest
    {
        return StockRequestResource::getEloquentQuery()->findOrFail($id);
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
        abort_unless(RequestsScreen::canView(), 403, 'You cannot see stock requests.');
    }
}
