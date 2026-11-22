<?php

namespace App\Http\Controllers\Office;

use App\Domain\Imports\ImportService;
use App\Domain\Imports\ImportSources;
use App\Domain\Imports\SheetFile;
use App\Domain\Launch\GoLiveService;
use App\Domain\Launch\PilotReport;
use App\Domain\Launch\PracticeCompany;
use App\Domain\Office\GoLiveScreen;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Branch;
use App\Models\DataImport;
use App\Models\GoLivePlan;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Support\Exports\Exporter;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Go-live and Import data in the new back office (App\Domain\Office\GoLiveScreen) — each step through the same service
 * as the current pages: GoLiveService, PracticeCompany, PilotReport and ImportService. An uploaded import file is kept
 * on the server under a token for this person while it is previewed, checked and imported.
 */
class GoLiveController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $r, bool $can): GoLiveScreen
    {
        $this->allowed($can);

        return new GoLiveScreen($r->user());
    }

    // -------------------------------------------------------------------------------------------- Go-live

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, GoLiveScreen::canGoLive())->goLive());
    }

    /**
     * plan · step (key, do) · old_totals · check (post and check again) · parallel_day · practice · archive_practice ·
     * report (preview) · send_report · snapshot · start_parallel · go_live (reason when steps are open) · roll_back (reason).
     */
    public function action(Request $request, GoLiveService $svc): JsonResponse
    {
        $screen = $this->screen($request, GoLiveScreen::canGoLive());
        $a = $request->validate(['action' => ['required', Rule::in(['plan', 'step', 'old_totals', 'check', 'parallel_day', 'practice', 'archive_practice', 'report', 'send_report',
            'snapshot', 'start_parallel', 'go_live', 'roll_back'])]])['action'];
        $c = Tenant::find($request->user()->tenant_id)->currency_code;
        $d = $request->validate(match ($a) {
            'plan' => ['sources' => ['required', 'array', 'min:1'], 'sources.*' => [Rule::in(array_keys(GoLivePlan::SOURCES))], 'cutover_mode' => ['required', Rule::in(array_keys(GoLivePlan::CUTOVER))],
                'pilot_mode' => ['required', Rule::in(array_keys(GoLivePlan::PILOT))], 'go_live_on' => ['nullable', 'date_format:Y-m-d'], 'parallel_until' => ['nullable', 'date_format:Y-m-d'],
                'pilot_branch_ids' => ['nullable', 'array'], 'pilot_branch_ids.*' => ['uuid'], 'support_email' => ['nullable', 'email', 'max:255'], 'pilot_report_time' => ['nullable', 'date_format:H:i']],
            'step' => ['key' => ['required', 'string', 'max:60'], 'do' => ['required', 'in:done,skip,undo'], 'note' => ['nullable', 'string', 'max:255']],
            'old_totals' => ['figures' => ['nullable', 'array'], 'figures.*' => ['nullable', 'numeric']],
            'parallel_day' => ['branch_id' => ['required', 'uuid'], 'day' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'old_sales' => ['required', 'numeric'],
                'old_cash' => ['nullable', 'numeric'], 'old_receipts' => ['nullable', 'integer', 'min:0'], 'note' => ['nullable', 'string', 'max:255']],
            'practice' => ['users' => ['nullable', 'array'], 'users.*' => ['uuid'], 'copy_catalogue' => ['boolean']],
            'go_live' => ['reason' => ['nullable', 'string', 'max:500']],
            'roll_back' => ['reason' => ['required', 'string', 'max:500']],
            default => [],
        }, ['sources.*' => 'Tick where the data comes from.', 'branch_id.*' => 'Choose the branch.', 'old_sales.*' => 'Enter the old system\'s total sales.',
            'day.*' => 'Choose the day (not in the future).', 'reason.*' => 'Say why.', 'support_email.*' => 'Enter a valid e-mail address.', 'pilot_report_time.*' => 'Enter the time as HH:MM.']);
        if (in_array($a, ['check', 'practice', 'snapshot'], true)) {
            @set_time_limit($a === 'check' ? 600 : 3600);
        }
        if ($a === 'go_live') {
            $open = $screen->goLive()['open_steps'];
            if ($open && trim((string) ($d['reason'] ?? '')) === '') {
                return response()->json(['error' => 'reason', 'detail' => 'Some steps are not finished: say why you go live anyway.'], 422);
            }
        }

        return $this->act(function (Actor $actor) use ($a, $d, $svc, $screen, $c) {
            $practice = $report = null;
            $message = match ($a) {
                'plan' => $svc->savePlan($actor, $d + ['go_live_on' => null, 'parallel_until' => null, 'pilot_branch_ids' => [], 'support_email' => null]) ? 'Plan saved.' : '',
                'step' => $svc->markStep($actor, $d['key'], $d['do'], ($d['note'] ?? '') ?: null) ? '' : '',
                'old_totals' => $svc->saveOldTotals($actor, array_map(fn ($v) => $v === null ? null : (string) $v, $d['figures'] ?? [])) ? 'Saved.' : '',
                'check' => ($r = $svc->reconciliation($screen->plan(), post: true))['ok'] ? 'Everything matches.' : count($r['problems']).' thing(s) to look at.',
                'parallel_day' => $svc->recordParallelDay($actor, Branch::findOrFail($d['branch_id']), $d['day'], Money::toMinor((string) $d['old_sales'], $c),
                    ($d['old_cash'] ?? '') !== '' && $d['old_cash'] !== null ? Money::toMinor((string) $d['old_cash'], $c) : null,
                    ($d['old_receipts'] ?? '') !== '' && $d['old_receipts'] !== null ? (int) $d['old_receipts'] : null, ($d['note'] ?? '') ?: null) ? 'Day recorded.' : '',
                'practice' => (function () use ($actor, $d, &$practice) {
                    $r = app(PracticeCompany::class)->create($actor, $d['users'] ?? [], (bool) ($d['copy_catalogue'] ?? true));
                    $practice = ['name' => $r['tenant']->name, 'logins' => $r['logins'], 'copied' => $r['copied'], 'problems' => $r['problems']];

                    return 'Practice company ready.';
                })(),
                'archive_practice' => (function () use ($actor) {
                    $pc = PracticeCompany::of($actor->tenantId) ?? throw new DomainException('There is no practice company.');
                    app(PracticeCompany::class)->archive($actor, $pc);

                    return 'Archived.';
                })(),
                'report' => ($report = app(PilotReport::class)->build($screen->plan())) ? '' : '',
                'send_report' => ($report = app(PilotReport::class)->send($screen->plan()))['sent_to'] ? "Sent to {$report['sent_to']}."
                    : 'Not sent: add the support e-mail in the plan, and check e-mail on System → Messaging.',
                'snapshot' => 'Backup taken: '.$svc->snapshot($actor)->file_name.'.',
                'start_parallel' => $svc->startParallel($actor) ? 'Parallel run started.' : '',
                'go_live' => $svc->goLive($actor, ($d['reason'] ?? '') ?: null) ? 'Live — congratulations.' : '',
                default => $svc->rollBack($actor, $d['reason']) ? 'Recorded — follow the rollback plan.' : '',
            };

            return ['message' => $message, 'golive' => $screen->goLive($practice, $report ? ['title' => $report['title'], 'lines' => $report['lines'], 'warnings' => $report['warnings']] : null)];
        });
    }

    // -------------------------------------------------------------------------------------------- Import data

    public function importPage(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, GoLiveScreen::canImport())->importPage((string) $request->query('type', '')));
    }

    private function type(GoLiveScreen $screen, Request $request): string
    {
        $type = (string) $request->input('type', $request->query('type', ''));
        abort_unless(isset($screen->types()[$type]), 403, 'You may not import this.');

        return $type;
    }

    private function export(Request $request, Exporter $ex, string $format, string $title, array $sheets, string $orientation = 'landscape'): StreamedResponse|JsonResponse
    {
        $actor = Actor::user($request->user(), 'web');
        try {
            return match ($format) {
                'pdf' => $ex->pdf($actor, $title, $sheets, [], $orientation),
                'xlsx' => $ex->xlsx($actor, $title, $sheets),
                default => $ex->csv($actor, $title, $sheets),
            };
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
    }

    /** template (headings and an example row) · guide (what each column means, PDF) · current (current data in the import layout). */
    public function download(Request $request, string $what, Exporter $ex): StreamedResponse|JsonResponse
    {
        $screen = $this->screen($request, GoLiveScreen::canImport());
        $type = $this->type($screen, $request);
        $i = ImportService::importer($type);
        $format = in_array($request->query('format'), ['xlsx', 'pdf'], true) ? (string) $request->query('format') : 'csv';
        if ($what === 'template') {
            return $this->export($request, $ex, $format === 'xlsx' ? 'xlsx' : 'csv', "{$i->label()} template", [['title' => $i->label(), 'headings' => $i->headings(),
                'rows' => [array_column($i->columns(), 'example')]]]);
        }
        if ($what === 'guide') {
            return $this->export($request, $ex, 'pdf', "{$i->label()} — column guide", [['title' => $i->label(), 'note' => $i->description(),
                'headings' => ['Column', 'Required', 'What to put in it', 'Example'],
                'rows' => array_map(fn ($c) => [$c['name'], $c['required'] === true ? 'Yes' : ($c['required'] === 'new' ? 'For new records' : 'No'), $c['help'], $c['example']], $i->columns())]], 'portrait');
        }
        $context = $screen->contextModel($type, (string) $request->query('context', '') ?: null);
        $rows = $i->exportRows(Actor::user($request->user(), 'web'), $screen->options($type, $request->query()), $context);
        if ($rows === null) {
            return response()->json(['error' => 'choose', 'detail' => $i->contextType() === 'stock_count' ? 'Choose the stock count first.' : 'Choose the warehouse first.'], 422);
        }
        $name = match ($type) {
            'stock_adjustment' => 'Stock sheet '.(Warehouse::find($context?->warehouse_id ?? $request->query('warehouse_id'))?->code ?? ''),
            'stock_count' => 'Count sheet '.($context?->number ?? ''),
            default => $i->label().' (current data)',
        };

        return $this->export($request, $ex, $format, $name, [['title' => $name, 'headings' => $i->headings(), 'rows' => is_array($rows) ? $rows : iterator_to_array($rows, false)]]);
    }

    /** A file was chosen: kept on the server under a token; its headings and first rows, and which of its columns are which. */
    public function upload(Request $request): JsonResponse
    {
        $screen = $this->screen($request, GoLiveScreen::canImport());
        $type = $this->type($screen, $request);
        $d = $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx'], 'source' => ['nullable', 'string', 'max:30']],
            ['file.required' => 'Choose the file first.', 'file.mimes' => 'The file must be CSV or Excel (.xlsx).', 'file.max' => 'The file is larger than 10 MB.']);
        $file = $d['file'];
        try {
            $data = SheetFile::read($file->getRealPath(), $file->getClientOriginalName());
        } catch (DomainException $e) {
            return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], 422);
        }
        $stored = $file->store('imports', 'local');
        $token = (string) Str::uuid7();
        Cache::put("office-import:{$request->user()->id}:{$token}", ['path' => $stored, 'name' => $file->getClientOriginalName(), 'hash' => hash_file('sha256', $file->getRealPath())], now()->addDay());
        $i = ImportService::importer($type);
        $source = isset(ImportSources::LABELS[$d['source'] ?? '']) ? $d['source'] : 'excel';
        $known = ImportSources::suggest($source, $type, $data['headings'], $i->headings()); // QuickBooks / other till names
        $mapping = [];
        foreach ($i->columns() as $c) {
            if (isset($known[$c['name']])) {
                $mapping[$c['name']] = $known[$c['name']];
            } elseif (! in_array($c['name'], $data['headings'], true)) {
                $guess = collect($data['headings'])->first(fn ($h) => ! in_array($h, $i->headings(), true) && (str_contains($h, $c['name']) || str_contains($c['name'], $h)));
                $mapping[$c['name']] = (string) $guess;
            }
        }

        return response()->json(['token' => $token, 'file' => $file->getClientOriginalName(), 'headings' => $data['headings'],
            'rows' => array_map(fn ($r) => $r['values'], array_slice($data['rows'], 0, 5)), 'total' => count($data['rows']), 'mapping' => (object) $mapping]);
    }

    /** Check (nothing is saved) or Import the uploaded file; a large one runs in the background. */
    public function run(Request $request, ImportService $svc): JsonResponse
    {
        $screen = $this->screen($request, GoLiveScreen::canImport());
        $type = $this->type($screen, $request);
        $d = $request->validate(['token' => ['required', 'uuid'], 'commit' => ['boolean'], 'mapping' => ['nullable', 'array'], 'mapping.*' => ['nullable', 'string', 'max:120'],
            'context' => ['nullable', 'uuid'], 'import_again' => ['boolean']], ['token.*' => 'Choose the file first.']);
        $kept = Cache::get("office-import:{$request->user()->id}:{$d['token']}") ?? abort(422, 'Choose the file again: it is no longer on the server.');
        $options = $screen->options($type, $request->all());
        if ($screen->asksIndustry($type) && ! isset($screen->industries($type)[$options['industry']])) {
            return response()->json(['error' => 'industry', 'detail' => 'Choose which industry these are for.'], 422);
        }
        $commit = (bool) ($d['commit'] ?? false);
        $again = (bool) ($d['import_again'] ?? false);
        $context = $screen->contextModel($type, $d['context'] ?? null);
        $mapping = array_filter($d['mapping'] ?? [], fn ($v) => $v !== null);
        @set_time_limit(1800);
        if ($commit && ! $again && ($done = ImportService::alreadyImported($type, $kept['hash'], $context?->getKey()))) {
            return response()->json(['duplicate' => "This file was already imported on {$done->completed_at?->format('j M Y H:i')} by {$done->user_name} ({$done->rows_ok} rows). "
                .'Importing it again would add it a second time.']);
        }
        $path = Storage::disk('local')->path($kept['path']);
        $rows = count(SheetFile::read($path, $kept['name'], $mapping)['rows'] ?? []);

        return $this->act(function (Actor $actor) use ($svc, $type, $path, $kept, $options, $again, $context, $commit, $mapping, $rows, $screen) {
            if ($rows > ImportService::$backgroundFrom) {
                $r = $svc->queue($actor, $type, $path, $kept['name'], $options + ['import_again' => $again], $context?->getKey(), $commit, $mapping, $rows);
                if (function_exists('fastcgi_finish_request')) {
                    app()->terminating(fn () => Artisan::call('erp:imports')); // start now, after the page has its answer
                }

                return ['message' => number_format($rows).' rows: '.($commit ? 'importing' : 'checking').' in the background. You can keep working or close this page; you get a notification when it is done.',
                    'import' => $screen->import($r)];
            }
            $r = $svc->run($actor, $type, $path, $kept['name'], $options + ['import_again' => $again], $context, $commit, $mapping);

            return ['message' => self::said($r), 'import' => $screen->import($r)];
        });
    }

    private static function said(DataImport $r): string
    {
        $n = $r->rows_error;

        return match ($r->status) {
            'checked' => $n ? "Checked: {$n} row(s) have errors — nothing was imported." : "Checked: all {$r->rows_total} row(s) are fine — press “Import this file” to import it.",
            'imported' => "Imported {$r->rows_ok} row(s)".($n ? ", skipped {$n} with errors." : '.'),
            default => "Not imported: {$n} row(s) have errors. Fix them (download the error report) or import skipping those rows.",
        };
    }

    public function importShow(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, GoLiveScreen::canImport());
        $r = DataImport::findOrFail($id);

        return response()->json(['import' => $screen->import($r), 'message' => in_array($r->status, ['queued', 'running'], true) ? null : self::said($r)]);
    }

    /** Import a checked file from the copy on the server, optionally skipping the rows with errors. */
    public function importChecked(Request $request, string $id, ImportService $svc): JsonResponse
    {
        $screen = $this->screen($request, GoLiveScreen::canImport());
        $r = DataImport::findOrFail($id);
        $skip = $request->boolean('skip_errors');
        @set_time_limit(1800);

        return $this->act(function (Actor $actor) use ($svc, $r, $skip, $screen) {
            $done = $svc->importChecked($actor, $r, $skip);
            if ($done->status === 'queued' && function_exists('fastcgi_finish_request')) {
                app()->terminating(fn () => Artisan::call('erp:imports'));
            }

            return ['message' => $done->status === 'queued' ? number_format($done->rows_total).' rows: importing in the background.' : self::said($done), 'import' => $screen->import($done)];
        });
    }

    /** The rows that failed, with the reason in the first column, to fix and import again. */
    public function errors(Request $request, string $id, Exporter $ex): StreamedResponse|JsonResponse
    {
        $this->screen($request, GoLiveScreen::canImport());
        $r = DataImport::findOrFail($id);
        $headings = array_keys($r->errors[0]['values'] ?? []);

        return $this->export($request, $ex, 'csv', 'Import errors '.$r->file_name, [['title' => 'Errors', 'headings' => ['row', 'error', ...$headings],
            'rows' => array_map(fn ($e) => [$e['row'], $e['message'], ...array_values(array_map(fn ($h) => $e['values'][$h] ?? '', $headings))], $r->errors ?? [])]]);
    }
}
