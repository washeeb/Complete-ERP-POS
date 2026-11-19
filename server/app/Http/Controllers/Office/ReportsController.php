<?php

namespace App\Http\Controllers\Office;

use App\Domain\Finance\HeadOfficeShare;
use App\Domain\Office\FinancialReportsScreen;
use App\Domain\Office\InsightsScreen;
use App\Domain\Office\ReportBuilderScreen;
use App\Domain\Office\ReportTables;
use App\Domain\Reports\ReportService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Support\Erp;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\SavedReport;
use App\Support\Exports\Exporter;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports in the new back office. Financial reports (App\Domain\Office\FinancialReportsScreen): the report chosen and
 * Share head office costs. Insights (InsightsScreen): the tab chosen. Report builder and saved reports
 * (ReportBuilderScreen, ReportService): run, open the rows behind a line, save, share, schedule, send now, delete.
 * Each exports as Excel, CSV or PDF through the Exporter (licence check, audit row), exactly as shown.
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

    public function builderOptions(Request $request): JsonResponse
    {
        $this->builderAllowed();

        return response()->json((new ReportBuilderScreen($request->user()))->options());
    }

    /** Run the builder's choices (and, for one line of a grouped report, the rows behind it). */
    public function builderRun(Request $request): JsonResponse
    {
        $this->builderAllowed();
        $d = $request->validate(['dataset' => ['required', 'string'], 'drill' => ['nullable', 'array']]);
        abort_unless(isset(ReportBuilderScreen::datasets()[$d['dataset']]), 403, 'You cannot use this data.');

        return $this->act(fn () => (new ReportBuilderScreen($request->user()))->run($d['dataset'], ReportBuilderScreen::definition($request->all()),
            self::drill($d['drill'] ?? [])));
    }

    /** Export the report shown: ?choices={JSON}&format=… */
    public function builderExport(Request $request, Exporter $exporter): StreamedResponse|JsonResponse
    {
        $this->builderAllowed();
        $c = json_decode((string) $request->query('choices', '{}'), true) ?: [];
        abort_unless(is_array($c) && isset(ReportBuilderScreen::datasets()[$c['dataset'] ?? '']), 403, 'You cannot use this data.');

        return $this->export($request, $exporter, function () use ($request, $c) {
            $screen = new ReportBuilderScreen($request->user());
            $def = ReportBuilderScreen::definition($c);
            $drill = self::drill($c['drill'] ?? []);
            $r = $screen->run($c['dataset'], $def, $drill);
            $name = filled($c['report'] ?? null) ? ($screen->saved((string) $c['report'])?->name ?? $r['title']) : $r['title'];
            $t = $r['table'];
            if (end($t['columns'])['label'] === '') { // the "Open" column is for the screen only
                array_pop($t['columns']);
                $t['rows'] = array_map(fn ($row) => ['cells' => array_slice($row['cells'], 0, -1)] + $row, $t['rows']);
            }

            return ['title' => $name, 'sheets' => ReportTables::sheets([$t], $name), 'filters' => array_values(array_filter(["Period: {$r['period']}",
                $def['branch_id'] ? 'Branch: '.(Branch::find($def['branch_id'])?->name ?? '') : null, $drill ? 'Detail of: '.implode(', ', $drill) : null]))];
        });
    }

    /** @return array<string, string> the line of a grouped report whose rows to show */
    private static function drill(mixed $drill): array
    {
        return array_map('strval', array_filter(is_array($drill) ? $drill : [], fn ($v) => $v !== null && ! is_array($v)));
    }

    public function savedReports(Request $request): JsonResponse
    {
        $this->builderAllowed();

        return response()->json((new ReportBuilderScreen($request->user()))->savedList((string) $request->query('q', '')));
    }

    /** A saved report: its details and the builder's choices. */
    public function savedReport(Request $request, string $report): JsonResponse
    {
        $this->builderAllowed();
        $screen = new ReportBuilderScreen($request->user());
        $r = $screen->saved($report) ?? abort(404);

        return response()->json(['report' => $screen->savedOf($r), 'choices' => ReportBuilderScreen::choices($r->dataset, $r->definition ?? [])]);
    }

    /** Save the report shown (new, or changes to one's own), with its sharing and schedule. */
    public function saveReport(Request $request, ReportService $reports): JsonResponse
    {
        $this->builderAllowed();
        $d = $request->validate([
            'id' => ['nullable', 'uuid'], 'name' => ['required', 'string', 'max:120'], 'shared' => ['boolean'], 'schedule' => ['nullable', 'string'],
            'schedule_day' => ['nullable', 'integer', 'min:1', 'max:28'], 'schedule_time' => ['nullable', 'string', 'max:5'], 'format' => ['nullable', 'in:pdf,xlsx,csv'],
            'recipients' => ['nullable', 'array'], 'recipients.*.type' => ['required', 'in:email,whatsapp,user'], 'recipients.*.to' => ['required', 'string', 'max:190'],
            'choices' => ['required', 'array'], 'choices.dataset' => ['required', 'string'],
        ], ['name.required' => 'Give the report a name.', 'recipients.*.to.required' => 'Fill in each recipient.']);
        $screen = new ReportBuilderScreen($request->user());
        $existing = filled($d['id'] ?? null) ? ($screen->saved($d['id']) ?? abort(404)) : null;
        $data = ['name' => $d['name'], 'shared' => (bool) ($d['shared'] ?? false), 'schedule' => $d['schedule'] ?? 'none', 'schedule_day' => $d['schedule_day'] ?? null,
            'schedule_time' => $d['schedule_time'] ?? '07:00', 'format' => $d['format'] ?? 'pdf', 'recipients' => $d['recipients'] ?? [],
            'dataset' => $d['choices']['dataset'], 'definition' => ReportBuilderScreen::definition((array) $request->input('choices'))];

        return $this->act(fn () => ['report' => $screen->savedOf($reports->save(Actor::user($request->user(), 'web'), $existing ?? new SavedReport, $data))]);
    }

    public function deleteReport(Request $request, string $report, ReportService $reports): JsonResponse
    {
        $this->builderAllowed();
        $r = (new ReportBuilderScreen($request->user()))->saved($report) ?? abort(404);

        return $this->act(function () use ($reports, $request, $r) {
            $reports->delete(Actor::user($request->user(), 'web'), $r);

            return ['deleted' => true];
        });
    }

    /** Send a scheduled report to its recipients now (who saved it only). */
    public function sendReport(Request $request, string $report, ReportService $reports): JsonResponse
    {
        $this->builderAllowed();
        $r = (new ReportBuilderScreen($request->user()))->saved($report) ?? abort(404);
        abort_unless($r->owner_id === $request->user()->id && $r->schedule !== 'none', 403, 'Only who saved a scheduled report sends it.');

        return $this->act(function () use ($reports, $r) {
            $reports->send($r);

            return ['message' => 'Sent to the recipients.'];
        });
    }

    /** One step, with its errors as the new back office expects them (422 with the reason, 403 when not allowed). */
    private function act(callable $fn): JsonResponse
    {
        try {
            return response()->json(DB::transaction($fn));
        } catch (AccessDenied $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage() ?: 'You may not do this here.'], 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
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

    private function builderAllowed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        abort_unless(ReportBuilderScreen::canView(), 403, 'You cannot build reports.');
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
