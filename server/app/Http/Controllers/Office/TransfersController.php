<?php

namespace App\Http\Controllers\Office;

use App\Domain\Inventory\TransferService;
use App\Domain\Office\TransfersScreen;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Http\Controllers\Controller;
use App\Models\StockTransfer;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Transfers in the new back office (App\Domain\Office\TransfersScreen), through TransferService. */
class TransfersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new TransfersScreen($request->user()))->list($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new TransfersScreen($request->user()))->options());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->allowed();

        return response()->json((new TransfersScreen($request->user()))->show($this->find($id)));
    }

    public function form(Request $request, ?string $id = null): JsonResponse
    {
        $this->allowed();
        $t = $id ? $this->find($id) : null;
        abort_unless($t ? StockTransferResource::canEdit($t) : StockTransferResource::canCreate(), 403, $t ? 'Only a draft can be changed, by the sending side.' : 'You cannot transfer stock.');

        return response()->json((new TransfersScreen($request->user()))->form($t));
    }

    public function save(Request $request, ?string $id = null): JsonResponse
    {
        $this->allowed();
        $t = $id ? $this->find($id) : new StockTransfer;
        $request->validate([
            'values' => ['required', 'array'], 'values.from_warehouse_id' => ['required', 'uuid'], 'values.to_warehouse_id' => ['required', 'uuid', 'different:values.from_warehouse_id'],
            'values.note' => ['nullable', 'string', 'max:2000'], 'values.lines' => ['required', 'array', 'min:1', 'max:500'],
            'values.lines.*.product_uom_id' => ['required', 'uuid'], 'values.lines.*.qty' => ['required'],
        ], ['values.to_warehouse_id.different' => 'Choose two different warehouses.', 'values.lines.required' => 'Add at least one line.',
            'values.lines.*.product_uom_id.required' => 'Choose the product on every line.']);

        return $this->act($request, fn (Actor $actor) => ['id' => (new TransfersScreen($request->user()))->save($actor, $t, (array) $request->input('values'))->id]);
    }

    /** Dispatch (stock leaves the sending warehouse), receive (with what actually arrived), or cancel a draft (with a reason). */
    public function action(Request $request, string $id, TransferService $svc): JsonResponse
    {
        $this->allowed();
        $d = $request->validate([
            'action' => ['required', Rule::in(['dispatch', 'receive', 'cancel'])], 'reason' => ['required_if:action,cancel', 'nullable', 'string', 'max:255'],
            'received' => ['required_if:action,receive', 'array'], 'received.*' => ['numeric', 'min:0'], 'to_bin_id' => ['nullable', 'uuid'], 'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $t = $this->find($id);

        return $this->act($request, function (Actor $actor) use ($svc, $t, $d) {
            match ($d['action']) {
                'dispatch' => $svc->dispatch($actor, $t),
                'receive' => $svc->receive($actor, $t, array_map(fn ($q) => (string) $q, (array) $d['received']), $d['to_bin_id'] ?? null, $d['note'] ?? null),
                default => $svc->cancel($actor, $t, $d['reason']),
            };

            return (new TransfersScreen($actor->user))->show($t->fresh());
        });
    }

    private function find(string $id): StockTransfer
    {
        return StockTransferResource::getEloquentQuery()->findOrFail($id);
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
        abort_unless(TransfersScreen::canView(), 403, 'You cannot see transfers.');
    }
}
