<?php

namespace App\Http\Controllers\Office;

use App\Domain\Finance\AccountingExport;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Pages\AccountingExportPage;
use App\Http\Controllers\Controller;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Export to QuickBooks / Xero / Sage in the new back office (release 8): the programs and a suggested period (last
 * month), and the journals file for a period, through AccountingExport, which checks the company-wide finance role.
 */
class AccountingExportController extends Controller
{
    public function options(): JsonResponse
    {
        $this->allowed();
        $last = now()->subMonthNoOverflow();

        return response()->json(['formats' => AccountingExport::FORMATS, 'from' => $last->copy()->startOfMonth()->toDateString(), 'to' => $last->copy()->endOfMonth()->toDateString()]);
    }

    public function download(Request $request, AccountingExport $export): Response
    {
        $this->allowed();
        $d = $request->validate(['format' => ['required', 'in:'.implode(',', array_keys(AccountingExport::FORMATS))], 'from' => ['required', 'date'], 'to' => ['required', 'date']],
            ['format.*' => 'Choose QuickBooks, Xero or Sage.', 'from.*' => 'Choose the first day.', 'to.*' => 'Choose the last day.']);
        try {
            return $export->download(Actor::user($request->user(), 'web'), $d['format'], $d['from'], $d['to']);
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage() ?: 'You may not do this here.'], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
    }

    private function allowed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(AccountingExportPage::canAccess(), 403, 'You cannot see this.');
    }
}
