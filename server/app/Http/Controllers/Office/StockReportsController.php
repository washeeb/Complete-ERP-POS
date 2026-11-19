<?php

namespace App\Http\Controllers\Office;

use App\Domain\Office\StockReportsScreen;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Support\Erp;
use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Support\Exports\Exporter;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stock reports in the new back office (App\Domain\Office\StockReportsScreen): Shrink, and Slow and dead stock with
 * Move (a draft transfer); each exports as Excel, CSV or PDF through the Exporter, as shown.
 */
class StockReportsController extends Controller
{
    public function shrink(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new StockReportsScreen($request->user()))->shrink($request->query()));
    }

    public function shrinkExport(Request $request, Exporter $exporter): StreamedResponse|JsonResponse
    {
        $this->allowed();

        return $this->export($request, $exporter, (new StockReportsScreen($request->user()))->shrinkSheets($request->query()));
    }

    public function slow(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new StockReportsScreen($request->user()))->slow($request->query()));
    }

    public function slowExport(Request $request, Exporter $exporter): StreamedResponse|JsonResponse
    {
        $this->allowed();

        return $this->export($request, $exporter, (new StockReportsScreen($request->user()))->slowSheets($request->query()));
    }

    /** Move slow stock to the store where it sells: a draft transfer, opened for checking and dispatching. */
    public function move(Request $request): JsonResponse
    {
        $this->allowed();
        $d = $request->validate(['warehouse' => ['required', 'uuid'], 'product' => ['required', 'uuid'], 'to' => ['required', 'uuid', 'different:warehouse']]);
        $w = Warehouse::findOrFail($d['warehouse']);
        abort_unless(Erp::can('stock.transfer', $w), 403, 'You cannot move stock from this store.');
        try {
            $t = DB::transaction(fn () => (new StockReportsScreen($request->user()))->move(Actor::user($request->user(), 'web'), $w->id, $d['product'], $d['to']));
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage() ?: 'You may not do this here.'], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }

        return response()->json(['transfer' => ['id' => $t->id, 'number' => $t->number, 'open' => "/office/transfers/{$t->id}"]]);
    }

    private function export(Request $request, Exporter $exporter, array $x): StreamedResponse|JsonResponse
    {
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

    private function allowed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(StockReportsScreen::canView(), 403, 'You cannot see stock reports.');
    }
}
