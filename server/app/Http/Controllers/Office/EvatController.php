<?php

namespace App\Http\Controllers\Office;

use App\Domain\Fiscal\FiscalService;
use App\Domain\Office\EvatScreen;
use App\Http\Controllers\Controller;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GRA E-VAT in the new back office (App\Domain\Office\EvatScreen): the reports, one report, Send again and Send the
 * waiting sales now. Sending talks to GRA, so it is not held inside a database transaction; FiscalService records the
 * answer (or the error and the next try) on the report itself, as the current screen does.
 */
class EvatController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->allowed(EvatScreen::canView());

        return response()->json((new EvatScreen($request->user()))->list($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed(EvatScreen::canView());

        return response()->json((new EvatScreen($request->user()))->options());
    }

    public function show(Request $request, string $report): JsonResponse
    {
        $this->allowed(EvatScreen::canView());
        $screen = new EvatScreen($request->user());

        return response()->json(['report' => $screen->show($screen->find($report) ?? abort(404))]);
    }

    /** Send one report again now (not one GRA already accepted). */
    public function retry(Request $request, string $report, FiscalService $fiscal): JsonResponse
    {
        $this->allowed(EvatScreen::canView());
        abort_unless(EvatScreen::canSend(), 403, 'You cannot send reports to GRA.');
        $screen = new EvatScreen($request->user());
        $sub = $screen->find($report) ?? abort(404);
        if ($sub->status === 'submitted') {
            return response()->json(['error' => 'already_submitted', 'detail' => 'GRA has already accepted this one.'], 422);
        }
        $sub = $fiscal->submit($sub->forceFill(['status' => 'pending']));

        return response()->json(['report' => $screen->show($sub->fresh()),
            'message' => $sub->status === 'submitted' ? 'Accepted by GRA.' : 'GRA did not accept it: '.($sub->error ?: 'no reason given').'.']);
    }

    /** Daily mode: send the till sales still waiting, up to today. */
    public function batch(Request $request, FiscalService $fiscal): JsonResponse
    {
        $this->allowed(EvatScreen::canView());
        abort_unless(EvatScreen::canSend(), 403, 'You cannot send reports to GRA.');
        $tenant = $request->user()->tenant_id;
        if ($fiscal->mode($tenant) !== 'daily') {
            return response()->json(['error' => 'not_daily', 'detail' => 'Sales are only sent in a batch when GRA E-VAT is set to the daily batch.'], 422);
        }
        $n = $fiscal->batch($tenant);

        return response()->json(['sent' => $n, 'message' => "{$n} sale(s) accepted by GRA."]);
    }

    private function allowed(bool $can): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless($can, 403, 'You cannot see this.');
    }
}
