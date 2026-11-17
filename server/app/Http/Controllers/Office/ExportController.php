<?php

namespace App\Http\Controllers\Office;

use App\Domain\Office\ListExport;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Http\Controllers\Controller;
use App\Support\Exports\Exporter;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** "Export" on the new back office's lists: Excel, CSV or PDF of what the list shows (App\Domain\Office\ListExport). */
class ExportController extends Controller
{
    public function export(Request $request, string $list, Exporter $exporter): StreamedResponse|JsonResponse
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(in_array($list, ListExport::LISTS, true), 404);
        abort_unless(ListExport::canView($list), 403, 'You cannot see this list.');
        $format = (string) $request->query('format', 'xlsx');
        abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 422, 'Choose Excel, CSV or PDF.');
        @set_time_limit(600); // a whole ledger

        $x = (new ListExport($request->user()))->sheet($list, $request->query());
        if ($format === 'pdf' && $x['count'] > Exporter::PDF_ROW_LIMIT) {
            return response()->json(['error' => 'too_many_rows', 'detail' => number_format($x['count']).' rows are too many for a PDF (about '
                .number_format(Exporter::PDF_ROW_LIMIT).' fit). Narrow the list with the filters, such as a date range, or export to Excel or CSV, which take everything.'], 422);
        }
        $actor = Actor::user($request->user(), 'web');
        try {
            return match ($format) {
                'pdf' => $exporter->pdf($actor, $x['title'], [$x['sheet']], $x['filters'], count($x['sheet']['headings']) > 5 ? 'landscape' : 'portrait'),
                'xlsx' => $exporter->xlsx($actor, $x['title'], [$x['sheet']], $x['filters']),
                default => $exporter->csv($actor, $x['title'], [$x['sheet']], $x['filters']),
            };
        } catch (DomainException $e) { // e.g. a licence that does not allow output
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
    }
}
