<?php

namespace App\Domain\Office;

use App\Domain\Imports\ImportService;
use App\Domain\Imports\ImportSources;
use App\Domain\Inventory\InventoryService;
use App\Domain\Launch\GoLiveService;
use App\Domain\Launch\PracticeCompany;
use App\Filament\Pages\GoLive;
use App\Filament\Pages\ImportData;
use App\Filament\Support\Erp;
use App\Models\BackupRun;
use App\Models\Branch;
use App\Models\Category;
use App\Models\DataImport;
use App\Models\GoLivePlan;
use App\Models\Industry;
use App\Models\ParallelDay;
use App\Models\StockAdjustment;
use App\Models\StockCount;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\IndustryContext;
use App\Support\Money;
use App\Support\Progress;
use Illuminate\Database\Eloquent\Model;

/**
 * The last two System pages in the new back office (part 24 of the rewrite), as the current pages: Go-live (the plan, the
 * checklist, the figures check, the parallel run, the practice company, the pilot report, the go-live backup and the
 * go-live / roll-back decisions) and Import data (template, check, import, background progress, history). Same rules:
 * GoLiveService, PracticeCompany, PilotReport and ImportService (GoLiveController).
 */
final class GoLiveScreen
{
    public function __construct(private readonly User $user) {}

    private static function choices(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private function currency(): string
    {
        return Tenant::find($this->user->tenant_id)?->currency_code ?? 'GHS';
    }

    /** An admin address as the new back office opens it (/admin/x → the path; the client maps it). */
    private static function path(?string $url): ?string
    {
        return $url ? ProductsScreen::path($url) : null;
    }

    // -------------------------------------------------------------------------------------------- Go-live

    public static function canGoLive(): bool
    {
        return GoLive::canAccess();
    }

    public function plan(): GoLivePlan
    {
        return app(GoLiveService::class)->plan($this->user->tenant_id);
    }

    public function goLive(?array $practice = null, ?array $report = null): array
    {
        $svc = app(GoLiveService::class);
        $plan = $this->plan();
        $fig = $svc->reconciliation($plan);
        $steps = $svc->steps($plan, $fig);
        $c = $this->currency();
        $exp = Money::exponent($c);
        $pc = PracticeCompany::of($this->user->tenant_id);
        $snap = $plan->snapshot_backup_id ? BackupRun::find($plan->snapshot_backup_id) : null;
        $branches = fn (array $ids) => $ids ? Branch::whereIn('id', $ids)->orderBy('code')->pluck('code')->implode(', ') : 'all branches';
        $open = array_column(array_filter($steps, fn ($s) => ! $s['done'] && ! $s['optional'] && $s['key'] !== 'go_live'), 'title');

        return ['currency' => $c, 'exponent' => $exp, 'exists' => $plan->exists, 'status' => $plan->status, 'status_label' => GoLivePlan::STATUSES[$plan->status],
            'live_since' => $plan->live_at?->format('j M Y'), 'rollback_reason' => $plan->rollback_reason,
            'summary' => ['sources' => collect($plan->sources)->map(fn ($s) => GoLivePlan::SOURCES[$s] ?? $s)->implode(', '), 'cutover' => GoLivePlan::CUTOVER[$plan->cutover_mode] ?? $plan->cutover_mode,
                'pilot' => (GoLivePlan::PILOT[$plan->pilot_mode] ?? $plan->pilot_mode).' · '.$branches($plan->pilot_branch_ids ?? []),
                'date' => ($plan->go_live_on?->format('j M Y') ?? 'not set').($plan->parallel_until ? ' · parallel until '.$plan->parallel_until->format('j M') : ''),
                'progress' => count(array_filter($steps, fn ($s) => $s['done'])).' of '.count($steps).' steps'],
            'plan' => ['sources' => array_values((array) $plan->sources), 'cutover_mode' => $plan->cutover_mode, 'pilot_mode' => $plan->pilot_mode,
                'go_live_on' => (string) $plan->go_live_on?->toDateString(), 'parallel_until' => (string) $plan->parallel_until?->toDateString(),
                'pilot_branch_ids' => array_values((array) $plan->pilot_branch_ids), 'support_email' => (string) $plan->support_email, 'pilot_report_time' => (string) ($plan->pilot_report_time ?? '19:00')],
            'choices' => ['sources' => self::choices(GoLivePlan::SOURCES), 'cutover' => self::choices(GoLivePlan::CUTOVER), 'pilot' => self::choices(GoLivePlan::PILOT),
                'branches' => self::choices(Branch::choices(null, 'both')),
                'users' => self::choices(User::where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn ($u) => [$u->id => "{$u->name} ({$u->username})"])->all())],
            'steps' => array_map(fn ($s) => ['key' => $s['key'], 'title' => $s['title'], 'why' => $s['why'], 'done' => (bool) $s['done'], 'skipped' => (bool) ($s['skipped'] ?? false),
                'optional' => (bool) $s['optional'], 'by' => $s['by'], 'note' => $s['note'], 'link' => ! $s['done'] ? self::path($s['link']) : null,
                'manual' => ! $s['auto'] && ! in_array($s['key'], ['go_live', 'reconcile', 'parallel'], true)], $steps),
            'figures' => ['rows' => array_map(fn ($r) => ['label' => $r['label'], 'money' => (bool) $r['money'], 'ours' => $r['ours'], 'old' => $r['old'], 'difference' => $r['difference']], $fig['rows']),
                'opening_left' => (int) $fig['opening_left'], 'problems' => array_values($fig['problems']), 'ok' => (bool) $fig['ok']],
            'old_totals' => collect(GoLiveService::FIGURES)->map(fn ($label, $k) => ['key' => $k, 'label' => $label, 'value' => isset($plan->old_totals[$k])
                ? (in_array($k, GoLiveService::MONEY_FIGURES, true) ? number_format($plan->old_totals[$k] / (10 ** $exp), $exp, '.', '') : (string) $plan->old_totals[$k]) : ''])->values()->all(),
            'parallel' => $plan->cutover_mode === 'parallel' ? ParallelDay::with('branch')->orderByDesc('day')->orderBy('branch_id')->limit(60)->get()->map(fn (ParallelDay $d) => ['id' => $d->id,
                'day' => $d->day->format('D j M'), 'branch' => $d->branch?->code, 'old' => (int) $d->old_sales_minor, 'new' => (int) $d->new_sales_minor, 'difference' => $d->differenceMinor(),
                'bad' => abs($d->differenceMinor()) > GoLiveService::PARALLEL_TOLERANCE_MINOR && ! $d->note, 'old_cash' => $d->old_cash_minor, 'new_cash' => $d->new_cash_minor,
                'note' => $d->note])->all() : null,
            'practice' => $pc ? ['name' => $pc->name] : null, 'practice_result' => $practice, 'report' => $report,
            'snapshot' => $snap ? ['file' => $snap->file_name, 'ago' => $snap->started_at?->diffForHumans(), 'status' => $snap->status] : null, 'open_steps' => $open,
            'actions' => ['snapshot' => $plan->status !== 'live', 'start_parallel' => $plan->cutover_mode === 'parallel' && in_array($plan->status, ['planning', 'rolled_back'], true),
                'go_live' => $plan->status !== 'live', 'roll_back' => in_array($plan->status, ['parallel', 'live'], true)]];
    }

    // -------------------------------------------------------------------------------------------- Import data

    public static function canImport(): bool
    {
        return ImportData::canAccess();
    }

    /** @return array<string, string> the imports this person may run */
    public function types(): array
    {
        return app(ImportService::class)->available($this->user);
    }

    public function warehouses(): array
    {
        $ids = Erp::branchIds('stock.adjust') ?? Erp::branchIds('stock.count');

        return Warehouse::when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))->orderBy('code')->get()->mapWithKeys(fn ($w) => [$w->id => "{$w->code} · {$w->name}"])->all();
    }

    /** The industries an industry import can go to (a customer belongs to one industry, so no "shared" for them). */
    public function industries(string $type): array
    {
        $choices = IndustryContext::choices($this->user);
        if (in_array($type, ['customers', 'customer_balances'], true)) {
            unset($choices[IndustryContext::SHARED]);
        }

        return $choices;
    }

    public function asksIndustry(string $type): bool
    {
        return ImportService::importer($type)->industryScoped() && Industry::licensed()->exists();
    }

    /** The records a file can go into (open counts, draft adjustments). */
    public function contexts(string $type): array
    {
        $reasons = InventoryService::ADJUSTMENT_REASONS;

        return match (ImportService::importer($type)->contextType()) {
            'stock_count' => StockCount::with('warehouse')->where('status', 'counting')->orderByDesc('started_at')->get()
                ->mapWithKeys(fn ($c) => [$c->id => "{$c->number} · {$c->warehouse->code} · started {$c->started_at->format('j M H:i')}"])->all(),
            'stock_adjustment' => StockAdjustment::with('warehouse')->where('status', 'draft')->orderByDesc('created_at')->get()
                ->mapWithKeys(fn ($a) => [$a->id => "{$a->number} · {$a->warehouse->code} · ".($reasons[$a->reason_code] ?? $a->reason_code)])->all(),
            default => [],
        };
    }

    public function contextModel(string $type, ?string $id): ?Model
    {
        if (! $id) {
            return null;
        }

        return match (ImportService::importer($type)->contextType()) {
            'stock_count' => StockCount::find($id),
            'stock_adjustment' => StockAdjustment::find($id),
            default => null,
        };
    }

    /** The importer's options from the page's choices, as the current page works them out. */
    public function options(string $type, array $p): array
    {
        $i = ImportService::importer($type);

        return ['skip_errors' => (bool) ($p['skip_errors'] ?? false), 'create_missing' => (bool) ($p['create_missing'] ?? true), 'allow_same_name' => (bool) ($p['allow_same_name'] ?? false),
            'warehouse_id' => ($p['warehouse_id'] ?? '') ?: null, 'reason_code' => ($p['reason_code'] ?? '') ?: 'correction', 'category_id' => ($p['category_id'] ?? '') ?: null,
            'source' => isset(ImportSources::LABELS[$p['source'] ?? '']) ? $p['source'] : 'excel', 'as_of' => ($p['as_of'] ?? '') ?: now()->toDateString(),
            'industry' => $this->asksIndustry($type) ? (string) ($p['industry'] ?? '') : ($i->industryScoped() ? 'shared' : (string) IndustryContext::selected())];
    }

    /** The page for one kind of import: its choices, columns and what can be downloaded. */
    public function importPage(string $type): array
    {
        $types = $this->types();
        $type = isset($types[$type]) ? $type : (string) array_key_first($types);
        $i = ImportService::importer($type);
        $ctx = $i->contextType();

        return ['type' => $type, 'types' => self::choices($types), 'sources' => self::choices(ImportSources::LABELS), 'description' => $i->description(),
            'asks_industry' => $this->asksIndustry($type), 'industries' => self::choices($this->industries($type)), 'industry' => (string) IndustryContext::selected(),
            'asks_as_of' => array_key_exists('as_of', $i->optionDefaults()), 'as_of' => GoLivePlan::first()?->go_live_on?->toDateString() ?? now()->toDateString(),
            'context' => $ctx, 'contexts' => self::choices($this->contexts($type)), 'warehouses' => self::choices($this->warehouses()),
            'reasons' => self::choices(InventoryService::ADJUSTMENT_REASONS), 'categories' => self::choices(Category::orderBy('name')->pluck('name', 'id')->all()),
            'current' => in_array($type, ['products_update', 'prices', 'customers', 'suppliers', 'accounts'], true) ? 'data' : ($ctx ?? null),
            'products' => in_array($type, ['products', 'products_update'], true), 'new_products' => $type === 'products',
            'columns' => array_map(fn ($c) => ['name' => $c['name'], 'required' => $c['required'] === true ? 'Yes' : ($c['required'] === 'new' ? 'New records' : ''), 'help' => $c['help'],
                'example' => $c['example']], $i->columns()),
            'history' => DataImport::orderByDesc('created_at')->limit(20)->get()->map(fn (DataImport $h) => ['id' => $h->id, 'when' => $h->created_at?->format('j M Y H:i'),
                'who' => $h->user_name, 'what' => ImportService::importer($h->type)->label(), 'file' => $h->file_name, 'status' => DataImport::STATUSES[$h->status] ?? $h->status,
                'rows' => (int) $h->rows_total, 'errors' => (int) $h->rows_error, 'checked' => $h->status === 'checked'])->all()];
    }

    /** One import: its result, or its progress while it runs in the background. */
    public function import(DataImport $r): array
    {
        $running = in_array($r->status, ['queued', 'running'], true);
        $p = $running ? Progress::live($r->user_id) : null;
        $link = match (true) {
            $r->status === 'imported' && $r->type === 'stock_adjustment' && $r->context_id => ['label' => 'Open the adjustment to approve and post it', 'open' => "/office/adjustments/{$r->context_id}"],
            $r->status === 'imported' && $r->type === 'stock_count' && $r->context_id => ['label' => 'Open the count to submit it', 'open' => "/office/counts/{$r->context_id}"],
            default => null,
        };

        return ['id' => $r->id, 'status' => $r->status, 'status_label' => DataImport::STATUSES[$r->status] ?? $r->status, 'file' => $r->file_name, 'running' => $running,
            'progress' => $running ? ($r->status === 'queued' || ! $p ? ['label' => $r->status === 'queued' ? 'Waiting to start (within a minute)' : 'Starting…', 'done' => 0, 'total' => (int) $r->rows_total, 'left' => null]
                : ['label' => $p['label'], 'done' => (int) $p['done'], 'total' => (int) $p['total'], 'left' => $p['left']]) : null,
            'total' => (int) $r->rows_total, 'ok' => (int) $r->rows_ok, 'errors' => (int) $r->rows_error,
            'counts' => collect(['created' => 'new', 'updated' => 'updated', 'added' => 'added', 'unchanged' => 'unchanged / skipped'])
                ->filter(fn ($l, $k) => ($r->summary[$k] ?? 0) > 0)->map(fn ($l, $k) => ($r->summary[$k]).' '.$l)->values()->all(),
            'ignored' => array_values($r->summary['ignored_columns'] ?? []), 'link' => $link,
            'results' => array_map(fn ($x) => ['row' => $x['row'], 'ref' => $x['ref'], 'outcome' => $x['outcome'], 'message' => $x['message']], array_slice($r->summary['results'] ?? [], 0, 300))];
    }
}
