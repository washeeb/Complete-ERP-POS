<?php

namespace App\Http\Controllers\Office;

use App\Domain\Office\ReturnsScreen;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ReturnsReport;
use App\Http\Controllers\Controller;
use App\Models\Sale;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Returns in the new back office (App\Domain\Office\ReturnsScreen): the list, its filters, and the return slip to sign. */
class ReturnsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new ReturnsScreen($request->user()))->list($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new ReturnsScreen($request->user()))->options());
    }

    /** The return slip (PDF): what came back, the refund, who took it and who approved it, with lines to sign. */
    public function slip(Request $request, string $sale): Response
    {
        $this->allowed();
        $s = (new ReturnsScreen($request->user()))->filtered([])->whereKey($sale)->first() ?? abort(404); // only a return this person may see
        try {
            return ReturnsReport::slipPdf(Actor::user($request->user(), 'web'), $s instanceof Sale ? $s : Sale::findOrFail($sale));
        } catch (DomainException $e) { // e.g. a licence that does not allow output
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
    }

    private function allowed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(ReturnsScreen::canView(), 403, 'You cannot see returns.');
    }
}
