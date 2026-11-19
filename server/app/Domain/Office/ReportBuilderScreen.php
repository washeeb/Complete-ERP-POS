<?php

namespace App\Domain\Office;

use App\Domain\Reports\ReportEngine;
use App\Filament\Pages\ReportBuilder as Page;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\SavedReport;
use App\Models\User;
use App\Support\IndustryContext;

/**
 * Report builder and saved reports in the new back office (release 7 of the rewrite): the current page's datasets (only
 * those this person may see), columns, figures, period, branch, text filter and sort, run through ReportEngine; a line
 * of a grouped report opens the rows behind it; save, share and schedule through ReportService (ReportsController).
 */
final class ReportBuilderScreen
{
    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return Page::canAccess();
    }

    /** @return array<string, string> the datasets this person may use */
    public static function datasets(): array
    {
        $out = [];
        foreach (ReportEngine::datasets() as $key => [$label, $perm, $companyWide]) {
            if (Erp::canAnywhere($perm) && (! $companyWide || Erp::branchIds($perm) === null)) {
                $out[$key] = $label;
            }
        }

        return $out;
    }

    public function options(): array
    {
        $all = ReportEngine::datasets();
        $sets = [];
        foreach (self::datasets() as $key => $label) {
            $f = ReportEngine::fields($key);
            $sets[] = ['id' => $key, 'label' => $label, 'dated' => (bool) $all[$key][3],
                'dims' => array_map(fn ($k, $d) => ['id' => $k, 'label' => $d[0]], array_keys($f['dims']), $f['dims']),
                'measures' => array_map(fn ($k, $m) => ['id' => $k, 'label' => $m[0]], array_keys($f['measures']), $f['measures'])];
        }
        $schedule = Erp::canAnywhere('reports.schedule');

        return [
            'datasets' => $sets, 'periods' => PurchaseOrdersScreen::choices(ReportEngine::PERIODS),
            'branches' => PurchaseOrdersScreen::choices(Branch::orderBy('code')->pluck('name', 'id')->all()),
            'schedules' => $schedule ? PurchaseOrdersScreen::choices(SavedReport::SCHEDULES) : [],
            'people' => $schedule ? PurchaseOrdersScreen::choices(IndustryContext::userScope(User::where('is_active', true))->orderBy('name')->pluck('name', 'id')->all()) : [],
            'max_rows' => ReportEngine::MAX_ROWS,
        ];
    }

    /** The builder's choices as ReportEngine's definition. @param  array<string, mixed>  $d */
    public static function definition(array $d): array
    {
        $filterDim = $d['filter_dim'] ?? null;
        $filterValue = trim((string) ($d['filter_value'] ?? ''));

        return ['dimensions' => array_values(array_map('strval', (array) ($d['dimensions'] ?? []))), 'measures' => array_values(array_map('strval', (array) ($d['measures'] ?? []))),
            'period' => isset(ReportEngine::PERIODS[$d['period'] ?? '']) ? $d['period'] : 'this_month', 'from' => $d['from'] ?? null, 'to' => $d['to'] ?? null,
            'branch_id' => filled($d['branch_id'] ?? null) ? (string) $d['branch_id'] : null,
            'filters' => $filterDim && $filterValue !== '' ? [(string) $filterDim => $filterValue] : [],
            'sort' => filled($d['sort'] ?? null) ? (string) $d['sort'] : null, 'direction' => ($d['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc'];
    }

    /** The definition back as the builder's choices (opening a saved report). */
    public static function choices(string $dataset, array $def): array
    {
        $f = (array) ($def['filters'] ?? []);

        return ['dataset' => $dataset, 'dimensions' => array_values((array) ($def['dimensions'] ?? [])), 'measures' => array_values((array) ($def['measures'] ?? [])),
            'period' => $def['period'] ?? 'this_month', 'from' => $def['from'] ?? null, 'to' => $def['to'] ?? null, 'branch_id' => $def['branch_id'] ?? null,
            'filter_dim' => array_key_first($f), 'filter_value' => $f ? (string) reset($f) : null, 'sort' => $def['sort'] ?? null, 'direction' => $def['direction'] ?? 'desc'];
    }

    /**
     * Run the report: a report table (figures to the right, the total row, "Open" to the record) with, on each line of a
     * grouped report, what opens the rows behind it.
     *
     * @param  array<string, string>  $drill
     */
    public function run(string $dataset, array $def, array $drill = []): array
    {
        $r = app(ReportEngine::class)->run($this->user, $dataset, $def, $drill);
        $f = ReportEngine::fields($dataset);
        $dims = array_values(array_intersect(array_keys($f['dims']), $def['dimensions']));
        $measures = array_values(array_intersect(array_keys($f['measures']), $def['measures']));
        $grouped = $measures !== [];
        $hasLinks = array_filter($r['links']) !== [];
        $cols = array_map(fn ($h, $i) => ($grouped && $i >= count($dims) ? '#' : '').$h, $r['headings'], array_keys($r['headings']));
        if ($hasLinks) {
            $cols[] = '';
        }
        $rows = [];
        foreach ($r['rows'] as $i => $cells) {
            $cells = array_map('strval', $cells);
            if ($hasLinks) {
                $cells[] = $r['links'][$i] ? ['t' => 'Open', 'to' => $r['links'][$i]] : '';
            }
            $total = $grouped && ! $r['raw'][$i];
            $rows[] = ['kind' => $total ? 'total' : null, 'cells' => $cells, 'drill' => ! $drill && $grouped && $dims && $r['raw'][$i] ? $r['raw'][$i] : null];
        }
        $table = ReportTables::table($r['title'], $cols, $rows, empty: 'Nothing in this period.');

        return ['title' => $r['title'], 'period' => $r['period'], 'truncated' => $r['truncated'], 'table' => $table,
            'hint' => $drill ? 'Rows behind: '.implode(' · ', $drill) : ($grouped && $dims ? 'Click a line to see the rows behind it.' : null)];
    }

    /** A saved report this person may open (their own, or shared). */
    public function saved(string $id): ?SavedReport
    {
        $r = SavedReport::find($id);

        return $r && ($r->owner_id === $this->user->id || $r->shared) ? $r : null;
    }

    public function savedOf(SavedReport $r): array
    {
        $tz = $this->user->tenant?->timezone ?: 'UTC';

        return ['id' => $r->id, 'name' => $r->name, 'dataset' => ReportEngine::datasets()[$r->dataset][0] ?? $r->dataset, 'by' => $r->owner_name, 'mine' => $r->owner_id === $this->user->id,
            'shared' => (bool) $r->shared, 'schedule' => $r->schedule, 'schedule_label' => SavedReport::SCHEDULES[$r->schedule] ?? $r->schedule,
            'next' => $r->schedule === 'none' ? null : $r->next_run_at?->setTimezone($tz)->format('D j M H:i'), 'last' => $r->last_status,
            'schedule_day' => $r->schedule_day, 'schedule_time' => $r->schedule_time, 'format' => $r->format, 'recipients' => $r->recipients ?? [],
            'open' => "/office/report-builder?report={$r->id}"];
    }

    /** Saved reports: this person's own and those shared with them, by name. */
    public function savedList(string $q = ''): array
    {
        $me = $this->user->id;
        $rows = SavedReport::where(fn ($w) => $w->where('owner_id', $me)->orWhere('shared', true))
            ->when(trim($q) !== '', fn ($w) => $w->where('name', 'ilike', '%'.trim($q).'%'))->orderBy('name')->get();

        return ['rows' => $rows->map(fn (SavedReport $r) => $this->savedOf($r))->all()];
    }
}
