<?php

namespace App\Http\Controllers\Office;

use App\Domain\Office\GoodsReceiptsScreen;
use App\Domain\Purchasing\GoodsReceiptService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Goods received notes in the new back office (App\Domain\Office\GoodsReceiptsScreen): the list, one note, and the
 * current page's buttons — accept or refuse a held delivery, reverse a posted one — through GoodsReceiptService.
 */
class GoodsReceiptsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new GoodsReceiptsScreen($request->user()))->list($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new GoodsReceiptsScreen($request->user()))->options());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->allowed();

        return response()->json((new GoodsReceiptsScreen($request->user()))->show($this->find($id)));
    }

    /** accept · refuse (reason) · reverse (reason) */
    public function action(Request $request, string $id, GoodsReceiptService $svc): JsonResponse
    {
        $this->allowed();
        $a = $request->validate(['action' => ['required', Rule::in(['accept', 'refuse', 'reverse'])]])['action'];
        $d = $request->validate($a === 'accept' ? [] : ['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Say why.']);
        $g = $this->find($id);
        $actor = Actor::user($request->user(), 'web');
        try {
            DB::transaction(fn () => match ($a) {
                'accept' => $svc->approveHeld($actor, $g),
                'refuse' => $svc->refuseHeld($actor, $g, $d['reason']),
                default => $svc->reverse($actor, $g, $d['reason']),
            });
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage() ?: 'You may not do this here.'], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }

        return response()->json((new GoodsReceiptsScreen($request->user()))->show($g->fresh()));
    }

    private function find(string $id): GoodsReceipt
    {
        return GoodsReceiptResource::getEloquentQuery()->findOrFail($id);
    }

    private function allowed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(GoodsReceiptsScreen::canView(), 403, 'You cannot see goods received.');
    }
}
