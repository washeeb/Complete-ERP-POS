<?php

namespace App\Domain\Launch;

use App\Domain\Backup\BackupService;
use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\GlCheck;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\BackupRun;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DataImport;
use App\Models\Device;
use App\Models\GoLivePlan;
use App\Models\ParallelDay;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Security\Authorizer;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Going live (Phase 14): the plan (where the data comes from, cut-over and pilot style), the checklist with what is
 * already done, the figures check against the old system, the parallel run, the backup taken just before going live,
 * and the record of going live or rolling back. The restore itself is `php artisan erp:backup:restore` (Chapter 12.3).
 */
final class GoLiveService
{
    /** Figures compared with the old system: key => label. Money ones are in minor units. */
    public const FIGURES = [
        'products' => 'Products', 'customers' => 'Customers', 'suppliers' => 'Suppliers',
        'stock_value' => 'Stock value at cost', 'receivables' => 'Customers owe (receivables)', 'payables' => 'Owed to suppliers (payables)',
        'gift_cards' => 'Gift cards and store credit unspent', 'loyalty' => 'Loyalty points (value)',
    ];

    public const MONEY_FIGURES = ['stock_value', 'receivables', 'payables', 'gift_cards', 'loyalty'];

    /** A parallel-run day is "clean" when the totals differ by no more than this (minor units): rounding, not a missing sale. */
    public const PARALLEL_TOLERANCE_MINOR = 100;

    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly GlCheck $glCheck,
        private readonly AutoPoster $poster,
    ) {}

    public function plan(string $tenantId): GoLivePlan
    {
        return GoLivePlan::withoutGlobalScopes()->where('tenant_id', $tenantId)->first()
            ?? new GoLivePlan(['tenant_id' => $tenantId, 'sources' => ['excel'], 'cutover_mode' => 'parallel', 'pilot_mode' => 'one_shop', 'status' => 'planning',
                'steps' => [], 'old_totals' => [], 'pilot_branch_ids' => [], 'pilot_report_time' => '19:00']);
    }

    /**
     * @param  array{sources?: list<string>, cutover_mode?: string, pilot_mode?: string, go_live_on?: ?string, parallel_until?: ?string,
     *               pilot_branch_ids?: list<string>, support_email?: ?string, pilot_report_time?: ?string}  $data
     */
    public function savePlan(Actor $actor, array $data): GoLivePlan
    {
        $this->require($actor);
        $plan = $this->plan($actor->tenantId);
        $sources = array_values(array_unique($data['sources'] ?? $plan->sources ?? ['excel']));
        if ($sources === [] || array_diff($sources, array_keys(GoLivePlan::SOURCES))) {
            throw new DomainException('Choose where the data comes from.');
        }
        if (in_array('fresh', $sources, true) && count($sources) > 1) {
            throw new DomainException('“Starting fresh” means nothing is brought over: untick the other sources, or untick “Starting fresh”.');
        }
        $cutover = $data['cutover_mode'] ?? $plan->cutover_mode;
        $pilot = $data['pilot_mode'] ?? $plan->pilot_mode;
        if (! isset(GoLivePlan::CUTOVER[$cutover]) || ! isset(GoLivePlan::PILOT[$pilot])) {
            throw new DomainException('Choose how to switch over and how to run the pilot.');
        }
        $goLive = ($data['go_live_on'] ?? null) ? Carbon::parse($data['go_live_on'])->toDateString() : null;
        $until = ($data['parallel_until'] ?? null) ? Carbon::parse($data['parallel_until'])->toDateString() : null;
        if ($goLive && $until && $until < $goLive) {
            throw new DomainException('The parallel run ends on or after the go-live date.');
        }
        $branches = array_values(array_filter($data['pilot_branch_ids'] ?? $plan->pilot_branch_ids ?? []));
        if (count($branches) !== Branch::whereIn('id', $branches)->count()) {
            throw new DomainException('Choose pilot branches of this company.');
        }
        if ($pilot === 'one_shop' && count($branches) > 1) {
            throw new DomainException('A one-shop pilot has one pilot branch: choose one, or switch to “Several shops at once”.');
        }
        $email = trim((string) ($data['support_email'] ?? $plan->support_email ?? '')) ?: null;
        if ($email && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('The support e-mail address does not look right.');
        }
        $time = (string) ($data['pilot_report_time'] ?? $plan->pilot_report_time ?? '19:00');
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new DomainException('The report time is HH:MM, e.g. 19:00.');
        }
        $plan->forceFill(['tenant_id' => $actor->tenantId, 'sources' => $sources, 'cutover_mode' => $cutover, 'pilot_mode' => $pilot, 'go_live_on' => $goLive,
            'parallel_until' => $cutover === 'parallel' ? $until : null, 'pilot_branch_ids' => $branches, 'support_email' => $email, 'pilot_report_time' => $time]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $plan, $plan->exists ? 'golive.plan_updated' : 'golive.plan_created'));
    }

    /**
     * The checklist for this plan, in order, with what is already done (found in the data) or ticked by hand.
     *
     * @return list<array{key: string, title: string, why: string, done: bool, auto: bool, skipped: bool, optional: bool, by: ?string, at: ?string, link: ?string}>
     */
    /** @param  array|null  $figures  reconciliation() when the caller already has it (it is the slowest part) */
    public function steps(GoLivePlan $plan, ?array $figures = null): array
    {
        $fresh = $plan->sources === ['fresh'];
        $imported = DataImport::where('status', 'imported')->pluck('type')->unique()->flip();
        $hasImport = fn (string $type) => isset($imported[$type]);
        $import = fn (string $type) => '/admin/import-data?type='.$type.'&source='.($this->firstSource($plan));
        $defs = [
            ['backup', 'Back up before loading anything', 'So you can go back to this point if a file goes wrong.', false,
                fn () => BackupRun::where('status', 'ok')->exists(), '/admin/backups'],
            ['practice', 'Staff practise in the practice company', 'People learn on made-up sales, not real money. Needed first for an internal dry run.', $plan->pilot_mode !== 'dry_run',
                fn () => Tenant::where('practice_of', $plan->tenant_id)->exists(), null],
            ['accounts', 'Chart of accounts', 'The accounts the opening balances go into. Your accountant checks it.', $fresh,
                fn () => $hasImport('accounts'), $import('accounts')],
            ['products', 'Products', 'What you sell, with barcodes and tax.', false, fn () => Product::exists(), $import('products')],
            ['prices', 'Prices', 'Selling prices on each price list.', false, fn () => $hasImport('prices') || DB::table('prices')->exists(), $import('prices')],
            ['customers', 'Customers', 'Account customers, and loyalty members if you use loyalty.', $fresh, fn () => Customer::exists(), $import('customers')],
            ['suppliers', 'Suppliers', 'Who you buy from.', $fresh, fn () => Supplier::exists(), $import('suppliers')],
            ['opening_stock', 'Opening stock', 'What is on the shelves on the go-live date, counted, at cost.', false,
                fn () => DB::table('stock_movements')->where('type', 'opening')->exists(), $import('opening_stock')],
            ['opening_balances', 'Opening trial balance', 'Bank, cash, assets, loans and capital from the old books.', $fresh,
                fn () => DB::table('journal_entries')->where('tenant_id', $plan->tenant_id)->where('kind', 'opening')->exists(), $import('opening_balances')],
            ['customer_balances', 'What customers owe', 'Open customer balances, collected later like invoices.', $fresh,
                fn () => DB::table('customer_ledger')->where('tenant_id', $plan->tenant_id)->where('type', 'opening')->exists(), $import('customer_balances')],
            ['supplier_balances', 'What you owe suppliers', 'Open supplier balances, paid later like bills.', $fresh,
                fn () => DB::table('supplier_ledger')->where('tenant_id', $plan->tenant_id)->where('type', 'opening')->exists(), $import('supplier_balances')],
            ['loyalty_points', 'Loyalty points', 'Points customers already earned.', true, fn () => $hasImport('loyalty_points'), $import('loyalty_points')],
            ['gift_cards', 'Gift cards and store credit', 'Unspent cards customers still hold.', true, fn () => $hasImport('gift_cards'), $import('gift_cards')],
            ['reconcile', 'Figures match the old system', 'Counts and balances agree with the old system on the go-live date.', false,
                fn () => ($figures ?? $this->reconciliation($plan))['ok'], null],
            ['devices', 'Every till ready', 'Enrolled, a test sale printed, the drawer opens, an offline sale syncs.', false,
                fn () => false, '/admin/devices'],
            ['training', 'Staff trained', 'Cashiers have the quick card; supervisors and managers have their guide.', false, fn () => false, null],
        ];
        if ($plan->cutover_mode === 'parallel') {
            $defs[] = ['parallel', 'Parallel run is clean', 'Several days in a row where both systems agree (differences explained).', false,
                fn () => $this->parallelClean($plan), null];
        } elseif ($plan->cutover_mode === 'one_till') {
            $defs[] = ['first_till', 'First till ran a clean week', 'One till or branch first; the others join once it runs a week without problems.', false, fn () => false, null];
        }
        $defs[] = ['go_live', 'Live', 'The old system is switched off for selling; this one is the books.', false, fn () => $plan->status === 'live', null];

        $out = [];
        foreach ($defs as [$key, $title, $why, $optional, $auto, $link]) {
            $manual = $plan->steps[$key] ?? [];
            $found = (bool) $auto();
            $out[] = ['key' => $key, 'title' => $title, 'why' => $why, 'optional' => (bool) $optional, 'auto' => $found, 'skipped' => ! empty($manual['skipped']),
                'done' => $found || ! empty($manual['done_at']), 'by' => $manual['by'] ?? null, 'at' => $manual['done_at'] ?? null, 'note' => $manual['note'] ?? null, 'link' => $link];
        }

        return $out;
    }

    /** Tick a step by hand ('done'), mark it not needed ('skip'), or undo that. */
    public function markStep(Actor $actor, string $key, string $action, ?string $note = null): GoLivePlan
    {
        $this->require($actor);
        $plan = $this->plan($actor->tenantId);
        if (! in_array($key, array_column($this->steps($plan), 'key'), true) || in_array($key, ['go_live', 'reconcile', 'parallel'], true)) {
            throw new DomainException('This step is ticked by the system when it is done.');
        }
        $steps = $plan->steps ?? [];
        $steps[$key] = match ($action) {
            'done' => ['done_at' => now()->toDateTimeString(), 'by' => $actor->name, 'note' => $note ? mb_substr($note, 0, 255) : null],
            'skip' => ['skipped' => true, 'done_at' => now()->toDateTimeString(), 'by' => $actor->name, 'note' => $note ? mb_substr($note, 0, 255) : null],
            'undo' => [],
            default => throw new DomainException('Unknown action.'),
        };
        if ($steps[$key] === []) {
            unset($steps[$key]);
        }
        $plan->steps = $steps;

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $plan, 'golive.step_'.$action, $note ?: $key));
    }

    /**
     * Ours next to the old system's figures. Automatic postings are brought up to date first, so the ledger ties.
     *
     * @return array{ok: bool, rows: list<array{key: string, label: string, ours: int, old: ?int, difference: ?int, money: bool}>, ledger_ok: bool,
     *               opening_left: int, unposted: int, problems: list<string>}
     */
    public function reconciliation(GoLivePlan $plan, bool $post = false): array
    {
        $tenantId = $plan->tenant_id;
        if ($post) {
            $this->poster->run($tenantId);
        }
        $sub = GlCheck::subLedgers($tenantId);
        $ours = [
            'products' => Product::count(), 'customers' => Customer::count(), 'suppliers' => Supplier::count(),
            'stock_value' => (int) ($sub['inventory'][1] ?? 0), 'receivables' => (int) ($sub['receivables'][1] ?? 0), 'payables' => -(int) ($sub['payables'][1] ?? 0),
            'gift_cards' => -(int) ($sub['stored_value'][1] ?? 0), 'loyalty' => -(int) ($sub['loyalty'][1] ?? 0),
        ];
        $rows = [];
        $problems = [];
        foreach (self::FIGURES as $key => $label) {
            $old = isset($plan->old_totals[$key]) && $plan->old_totals[$key] !== null && $plan->old_totals[$key] !== '' ? (int) $plan->old_totals[$key] : null;
            $diff = $old === null ? null : $ours[$key] - $old;
            $rows[] = ['key' => $key, 'label' => $label, 'ours' => $ours[$key], 'old' => $old, 'difference' => $diff, 'money' => in_array($key, self::MONEY_FIGURES, true)];
            if ($diff) {
                $problems[] = "{$label} differs from the old system.";
            }
        }
        $gl = $this->glCheck->run($tenantId, $sub);
        $unposted = array_sum($this->poster->unposted($tenantId));
        if ($unposted > 0) {
            $problems[] = "{$unposted} record(s) are not posted to the accounts yet (press “Post and check again”).";
        }
        if ($gl['opening_left'] !== 0) {
            $problems[] = '“Opening balances (to clear)” is not zero: '.Money::format(abs($gl['opening_left']), Tenant::find($tenantId)->currency_code)
                .' — something from the old trial balance is missing or loaded twice.';
        }

        return ['ok' => $problems === [] && $gl['ok'] && Product::exists(), 'rows' => $rows, 'ledger_ok' => $gl['ok'], 'opening_left' => $gl['opening_left'],
            'unposted' => $unposted, 'problems' => array_merge($problems, $gl['problems'])];
    }

    /** @param array<string, string|int|null> $figures as typed (money in currency units) */
    public function saveOldTotals(Actor $actor, array $figures): GoLivePlan
    {
        $this->require($actor);
        $plan = $this->plan($actor->tenantId);
        $currency = Tenant::find($actor->tenantId)->currency_code;
        $out = [];
        foreach (self::FIGURES as $key => $label) {
            $v = trim((string) ($figures[$key] ?? ''));
            if ($v === '') {
                continue;
            }
            $clean = str_replace([',', ' '], '', $v);
            if (! is_numeric($clean)) {
                throw new DomainException("{$label}: “{$v}” is not a number.");
            }
            $out[$key] = in_array($key, self::MONEY_FIGURES, true) ? Money::toMinor($clean, $currency) : (int) $clean;
        }
        $plan->old_totals = $out;

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $plan, 'golive.old_totals_saved'));
    }

    /** One day of the parallel run: the old system's totals; ours are worked out from the sales posted that day. */
    public function recordParallelDay(Actor $actor, Branch $branch, string $day, int $oldSalesMinor, ?int $oldCashMinor, ?int $oldReceipts, ?string $note): ParallelDay
    {
        $this->require($actor);
        $date = Carbon::parse($day)->toDateString();
        if ($date > now()->toDateString()) {
            throw new DomainException('That day has not happened yet.');
        }
        $ours = $this->dayTotals($branch, $date);
        $row = ParallelDay::where('branch_id', $branch->id)->where('day', $date)->first() ?? new ParallelDay;
        $row->forceFill(['tenant_id' => $actor->tenantId, 'branch_id' => $branch->id, 'day' => $date, 'old_sales_minor' => $oldSalesMinor, 'old_cash_minor' => $oldCashMinor,
            'old_receipts' => $oldReceipts, 'new_sales_minor' => $ours['sales'], 'new_cash_minor' => $ours['cash'], 'new_receipts' => $ours['receipts'],
            'note' => $note ? mb_substr(trim($note), 0, 255) : null, 'entered_by_name' => mb_substr($actor->name, 0, 120)]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $row, $row->exists ? 'golive.parallel_day_updated' : 'golive.parallel_day_recorded',
            branchId: $branch->id));
    }

    /** @return array{sales: int, cash: int, receipts: int} for a branch and day in the company's time zone */
    public function dayTotals(Branch $branch, string $date): array
    {
        $tz = Tenant::find($branch->tenant_id)->timezone ?: 'UTC';
        $from = Carbon::parse($date, $tz)->startOfDay()->utc();
        $to = $from->copy()->addDay();
        $sales = DB::table('sales')->where('branch_id', $branch->id)->where('status', 'posted')->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to);
        $cash = DB::table('sale_payments')->join('sales', 'sales.id', '=', 'sale_payments.sale_id')->where('sales.branch_id', $branch->id)->where('sales.status', 'posted')
            ->where('sales.occurred_at', '>=', $from)->where('sales.occurred_at', '<', $to)->where('sale_payments.method', 'cash')->sum('sale_payments.amount_minor');

        return ['sales' => (int) (clone $sales)->sum('total_minor'), 'cash' => (int) $cash, 'receipts' => (clone $sales)->where('total_minor', '>', 0)->count()];
    }

    /** At least three days recorded, the latest three in a row within the tolerance (or explained with a note). */
    public function parallelClean(GoLivePlan $plan): bool
    {
        $days = ParallelDay::withoutGlobalScopes()->where('tenant_id', $plan->tenant_id)->orderByDesc('day')->get()->groupBy(fn ($d) => $d->day->toDateString())->take(3);
        if ($days->count() < 3) {
            return false;
        }

        return $days->flatten()->every(fn (ParallelDay $d) => abs($d->differenceMinor()) <= self::PARALLEL_TOLERANCE_MINOR || trim((string) $d->note) !== '');
    }

    /** The backup taken just before going live (the rollback point). */
    public function snapshot(Actor $actor): BackupRun
    {
        $this->require($actor);
        try {
            $run = app(BackupService::class)->run("Before go-live ({$actor->name})");
        } catch (DomainException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new DomainException('The backup did not run: '.$e->getMessage().' Fix it on System → Backups, then try again.');
        }
        if (! in_array($run->status, ['ok', 'partial'], true)) {
            throw new DomainException('The backup failed: '.($run->error ?: 'see System → Backups').'.');
        }
        $plan = $this->plan($actor->tenantId);
        $plan->snapshot_backup_id = $run->id;
        $this->recorder->transaction(fn () => $this->recorder->save($actor, $plan, 'golive.snapshot_taken', $run->file_name));

        return $run;
    }

    public function startParallel(Actor $actor): GoLivePlan
    {
        $this->require($actor);
        $plan = $this->plan($actor->tenantId);
        if ($plan->cutover_mode !== 'parallel' || ! in_array($plan->status, ['planning', 'rolled_back'], true)) {
            throw new DomainException('A parallel run starts from the planning stage of a parallel cut-over.');
        }
        $plan->status = 'parallel';

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $plan, 'golive.parallel_started'));
    }

    /** Go live: needs the rollback backup from today, and either the figures checked or an explicit reason to go anyway. */
    public function goLive(Actor $actor, ?string $overrideReason = null): GoLivePlan
    {
        $this->require($actor);
        $plan = $this->plan($actor->tenantId);
        if ($plan->status === 'live') {
            throw new DomainException('Already live.');
        }
        $snap = $plan->snapshot_backup_id ? BackupRun::find($plan->snapshot_backup_id) : null;
        if (! $snap || ! $snap->started_at->isToday()) {
            throw new DomainException('Take the go-live backup first (it is the point you can go back to).');
        }
        $open = array_filter($this->steps($plan), fn ($s) => ! $s['done'] && ! $s['optional'] && $s['key'] !== 'go_live');
        if ($open && trim((string) $overrideReason) === '') {
            throw new DomainException('Not finished yet: '.implode(', ', array_column($open, 'title')).'. Finish them, or give the reason for going live anyway.');
        }
        $plan->forceFill(['status' => 'live', 'live_at' => now(), 'rollback_reason' => null]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $plan, 'golive.live', $open ? 'Gone live with open steps: '.trim((string) $overrideReason) : null));
    }

    /** Record the decision to go back to the old system; the technician then restores the go-live backup (Chapter 12.3). */
    public function rollBack(Actor $actor, string $reason): GoLivePlan
    {
        $this->require($actor);
        if (trim($reason) === '') {
            throw new DomainException('Give the reason for going back.');
        }
        $plan = $this->plan($actor->tenantId);
        if (! in_array($plan->status, ['parallel', 'live'], true)) {
            throw new DomainException('Only a parallel run or a live system can be rolled back.');
        }
        $plan->forceFill(['status' => 'rolled_back', 'rollback_reason' => mb_substr(trim($reason), 0, 255)]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $plan, 'golive.rolled_back', $reason));
    }

    /** Tills that are enrolled, per branch (for the "every till ready" step). */
    public function devicesByBranch(): array
    {
        return Device::where('status', 'active')->whereNotNull('till_id')->get()->groupBy('branch_id')->map->count()->all();
    }

    private function firstSource(GoLivePlan $plan): string
    {
        foreach ($plan->sources ?? [] as $s) {
            if (in_array($s, ['quickbooks', 'other_pos'], true)) {
                return $s;
            }
        }

        return 'excel';
    }

    private function require(Actor $actor): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'golive.manage')) {
            throw new AccessDenied;
        }
    }
}
