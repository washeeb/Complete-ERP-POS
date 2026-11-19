<?php

namespace App\Http\Controllers\Office;

use App\Domain\Finance\HeadOfficeShare;
use App\Domain\Office\FinancialReportsScreen;
use App\Domain\Office\InsightsScreen;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Support\Erp;
use App\Http\Controllers\Controller;
use App\Support\Exports\Exporter;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports in the new back office. Financial reports (App\Domain\Office\FinancialReportsScreen): the report chosen and
 * Share head office costs. Insights (InsightsScreen): the tab chosen. Each exports as Excel, CSV or PDF through the
 * Exporter (licence check, audit row), exactly as shown.
 */
class ReportsController extends Controller
{
    public function financialOptions(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new FinancialReportsScreen($request->user()))->options());
    }

    public function financial(Request $request): JsonResponse
    {
        $this->allowed();

        return response()->json((new FinancialReportsScreen($request->user()))->report($request->query()));
    }

    public function financialExport(Request $request, Exporter $exporter): StreamedResponse|JsonResponse
    {
        $this->allowed();

        return $this->export($request, $exporter, fn () => (new FinancialReportsScreen($request->user()))->sheets($request->query()));
    }

    public function insights(Request $request): JsonResponse
    {
        $this->insightsAllowed();

        return response()->json((new InsightsScreen($request->user()))->show($request->query()));
    }

    public function insightsExport(Request $request, Exporter $exporter): StreamedResponse|JsonResponse
    {
        $this->insightsAllowed();

        return $this->export($request, $exporter, fn () => (new InsightsScreen($request->user()))->sheets($request->query()));
    }

    /** @param  callable(): array{title: string, sheets: list<array>, filters: list<string>}  $make */
    private function export(Request $request, Exporter $exporter, callable $make): StreamedResponse|JsonResponse
    {
        $format = (string) $request->query('format', 'xlsx');
        abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 422, 'Choose Excel, CSV or PDF.');
        @set_time_limit(600); // a long account ledger
        $x = $make();
        $rows = array_sum(array_map(fn ($s) => count($s['rows']), $x['sheets']));
        if ($format === 'pdf' && $rows > Exporter::PDF_ROW_LIMIT) {
            return response()->json(['error' => 'too_many_rows', 'detail' => number_format($rows).' rows are too many for a PDF (about '
                .number_format(Exporter::PDF_ROW_LIMIT).' fit). Choose shorter dates, or export to Excel or CSV, which take everything.'], 422);
        }
        $actor = Actor::user($request->user(), 'web');
        try {
            return match ($format) {
                'pdf' => $exporter->pdf($actor, $x['title'], $x['sheets'], $x['filters'], max(array_map(fn ($s) => count($s['headings']), $x['sheets'])) > 4 ? 'landscape' : 'portrait'),
                'xlsx' => $exporter->xlsx($actor, $x['title'], $x['sheets'], $x['filters']),
                default => $exporter->csv($actor, $x['title'], $x['sheets'], $x['filters']),
            };
        } catch (DomainException $e) { // e.g. a licence that does not allow output
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
    }

    /** Share head office costs for the month of the To date: one journal, which can be reversed. */
    public function shareHeadOffice(Request $request, HeadOfficeShare $share): JsonResponse
    {
        $this->allowed();
        abort_unless(Erp::canAnywhere('finance.journal'), 403, 'You cannot post journals.');
        $d = $request->validate(['to' => ['required', 'date']]);
        try {
            $j = DB::transaction(fn () => $share->post(Actor::user($request->user(), 'web'), $d['to']));
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage() ?: 'You may not do this here.'], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }

        return response()->json(['message' => "Head office costs shared in {$j->number}."]);
    }

    private function insightsAllowed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(InsightsScreen::canView() && InsightsScreen::tabs() !== [], 403, 'You cannot see Insights.');
    }

    private function allowed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(FinancialReportsScreen::canView(), 403, 'You cannot see the financial reports.');
    }
}
