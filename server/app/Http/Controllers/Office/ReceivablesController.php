<?php

namespace App\Http\Controllers\Office;

use App\Domain\Office\ReceivablesScreen;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Payments received and Aged receivables in the new back office (App\Domain\Office\ReceivablesScreen): read-only lists, as the current pages. */
class ReceivablesController extends Controller
{
    use ActsOnAccess;

    public function payments(Request $request): JsonResponse
    {
        $this->allowed(ReceivablesScreen::canViewPayments());

        return response()->json((new ReceivablesScreen($request->user()))->payments($request->query()));
    }

    public function options(Request $request): JsonResponse
    {
        $this->allowed(ReceivablesScreen::canViewPayments());

        return response()->json((new ReceivablesScreen($request->user()))->options());
    }

    public function aging(Request $request): JsonResponse
    {
        $this->allowed(ReceivablesScreen::canViewAging());

        return response()->json((new ReceivablesScreen($request->user()))->aging());
    }
}
