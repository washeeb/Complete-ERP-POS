<?php

namespace App\Filament\Pages;

use App\Domain\Launch\GoLiveService;
use App\Domain\Launch\PilotReport;
use App\Domain\Launch\PracticeCompany;
use App\Filament\Support\Erp;
use App\Models\BackupRun;
use App\Models\Branch;
use App\Models\GoLivePlan;
use App\Models\ParallelDay;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Going live (Phase 14): the plan, the checklist, the figures check against the old system, the parallel run, the
 * practice company, the pilot report, and the go-live / roll-back decisions with the backup that makes going back safe.
 */
class GoLive extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Go-live';

    protected static ?string $title = 'Going live';

    protected static ?string $slug = 'go-live';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.go-live';

    /** Practice logins just created (shown once). */
    public ?array $practiceResult = null;

    public ?array $reportPreview = null;

    public static function canAccess(): bool
    {
        return Erp::can('golive.manage') && ! Tenant::isPractice(Erp::user()?->tenant_id);
    }

    public function plan(): GoLivePlan
    {
        return app(GoLiveService::class)->plan(Erp::user()->tenant_id);
    }

    public function steps(): array
    {
        return app(GoLiveService::class)->steps($this->plan());
    }

    public function figures(): array
    {
        return app(GoLiveService::class)->reconciliation($this->plan());
    }

    public function money(?int $minor): string
    {
        return $minor === null ? '—' : Money::format($minor, Tenant::find(Erp::user()->tenant_id)->currency_code);
    }

    public function parallelDays()
    {
        return ParallelDay::with('branch')->orderByDesc('day')->orderBy('branch_id')->limit(60)->get();
    }

    public function practice(): ?Tenant
    {
        return PracticeCompany::of(Erp::user()->tenant_id);
    }

    public function snapshot(): ?BackupRun
    {
        $id = $this->plan()->snapshot_backup_id;

        return $id ? BackupRun::find($id) : null;
    }

    public function branchNames(array $ids): string
    {
        return $ids ? Branch::whereIn('id', $ids)->orderBy('code')->pluck('code')->implode(', ') : 'all branches';
    }

    protected function getHeaderActions(): array
    {
        return [$this->planAction()];
    }

    public function planAction(): Action
    {
        return Action::make('plan')->label('Plan')->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading('The go-live plan')
            ->fillForm(function () {
                $p = $this->plan();

                return ['sources' => $p->sources, 'cutover_mode' => $p->cutover_mode, 'pilot_mode' => $p->pilot_mode, 'go_live_on' => $p->go_live_on?->toDateString(),
                    'parallel_until' => $p->parallel_until?->toDateString(), 'pilot_branch_ids' => $p->pilot_branch_ids, 'support_email' => $p->support_email,
                    'pilot_report_time' => $p->pilot_report_time];
            })
            ->schema([
                CheckboxList::make('sources')->label('Where does the data come from?')->options(GoLivePlan::SOURCES)->required()
                    ->helperText('Tick every source you will import from. “Starting fresh” = nothing is brought over.'),
                Radio::make('cutover_mode')->label('How do you switch over?')->options(GoLivePlan::CUTOVER)->required()
                    ->descriptions(['parallel' => 'Both systems run side by side for one to two weeks; the daily totals are compared before the old one is switched off.',
                        'big_bang' => 'Load the data on a quiet day and use only the new system the next morning. The go-live backup is the way back.',
                        'one_till' => 'Start with one till or branch; the rest join after a week of clean days.']),
                Radio::make('pilot_mode')->label('How is the pilot run?')->options(GoLivePlan::PILOT)->required()
                    ->descriptions(['one_shop' => 'One real shop goes first, with support on call; a report is e-mailed every evening.',
                        'several' => 'Two or three shops with different set-ups start together; same support tools.',
                        'dry_run' => 'A full simulated week in the practice company before any real shop.']),
                DatePicker::make('go_live_on')->label('Go-live date (opening balances are “as of” this date)'),
                DatePicker::make('parallel_until')->label('Parallel run until')->visible(fn ($get) => $get('cutover_mode') === 'parallel'),
                Select::make('pilot_branch_ids')->label('Pilot branch(es)')->multiple()->options(fn () => Branch::orderBy('code')->get()->mapWithKeys(fn ($b) => [$b->id => "{$b->code} · {$b->name}"]))
                    ->helperText('Empty = all branches.'),
                TextInput::make('support_email')->label('Support e-mail (problem reports and the evening pilot report)')->email(),
                TextInput::make('pilot_report_time')->label('Pilot report time (HH:MM)')->default('19:00'),
            ])
            ->action(fn (array $data) => Erp::run(fn ($actor) => app(GoLiveService::class)->savePlan($actor, $data), 'Plan saved'));
    }

    public function markStepAction(): Action
    {
        return Action::make('markStep')->label(fn (array $arguments) => match ($arguments['do'] ?? 'done') {
            'skip' => 'Not needed', 'undo' => 'Undo', default => 'Done'
        })
            ->link()->size('sm')
            ->schema(fn (array $arguments) => ($arguments['do'] ?? 'done') === 'undo' ? [] : [TextInput::make('note')->label('Note (optional)')->maxLength(255)])
            ->action(fn (array $data, array $arguments) => Erp::run(fn ($actor) => app(GoLiveService::class)->markStep($actor, $arguments['key'], $arguments['do'] ?? 'done', $data['note'] ?? null)));
    }

    public function oldTotalsAction(): Action
    {
        return Action::make('oldTotals')->label('Enter the old system’s figures')->icon(Heroicon::OutlinedCalculator)->color('gray')
            ->modalDescription('From the old system on the go-live date (its stock valuation, aged debtors and creditors reports). Leave a box empty to skip that check.')
            ->fillForm(function () {
                $p = $this->plan();
                $c = Tenant::find(Erp::user()->tenant_id)->currency_code;
                $exp = Money::exponent($c);

                return collect(GoLiveService::FIGURES)->mapWithKeys(fn ($l, $k) => [$k => isset($p->old_totals[$k])
                    ? (in_array($k, GoLiveService::MONEY_FIGURES, true) ? number_format($p->old_totals[$k] / (10 ** $exp), $exp, '.', '') : (string) $p->old_totals[$k]) : null])->all();
            })
            ->schema(collect(GoLiveService::FIGURES)->map(fn ($label, $key) => TextInput::make($key)->label($label))->values()->all())
            ->action(fn (array $data) => Erp::run(fn ($actor) => app(GoLiveService::class)->saveOldTotals($actor, $data), 'Saved'));
    }

    public function postAndCheck(): void
    {
        @set_time_limit(600);
        $r = app(GoLiveService::class)->reconciliation($this->plan(), post: true);
        Notification::make()->title($r['ok'] ? 'Everything matches' : count($r['problems']).' thing(s) to look at')->{$r['ok'] ? 'success' : 'warning'}()->send();
    }

    public function parallelDayAction(): Action
    {
        return Action::make('parallelDay')->label('Enter a day of the old system')->icon(Heroicon::OutlinedPlus)
            ->modalDescription('The old system’s end-of-day totals for one branch. This system’s totals for the same day are filled in for you.')
            ->schema([
                Select::make('branch_id')->label('Branch')->options(fn () => Branch::orderBy('code')->get()->mapWithKeys(fn ($b) => [$b->id => "{$b->code} · {$b->name}"]))->required(),
                DatePicker::make('day')->default(now()->toDateString())->required()->maxDate(now()),
                TextInput::make('old_sales')->label('Old system: total sales')->required()->numeric(),
                TextInput::make('old_cash')->label('Old system: cash')->numeric(),
                TextInput::make('old_receipts')->label('Old system: number of receipts')->integer(),
                Textarea::make('note')->label('Explanation of any difference')->rows(2)->maxLength(255),
            ])
            ->action(function (array $data) {
                $c = Tenant::find(Erp::user()->tenant_id)->currency_code;
                Erp::run(fn ($actor) => app(GoLiveService::class)->recordParallelDay($actor, Branch::findOrFail($data['branch_id']), $data['day'], Money::toMinor((string) $data['old_sales'], $c),
                    ($data['old_cash'] ?? '') !== '' ? Money::toMinor((string) $data['old_cash'], $c) : null, ($data['old_receipts'] ?? '') !== '' ? (int) $data['old_receipts'] : null, $data['note'] ?? null), 'Day recorded');
            });
    }

    public function createPracticeAction(): Action
    {
        return Action::make('createPractice')->label('Create the practice company')->icon(Heroicon::OutlinedAcademicCap)
            ->modalDescription('A copy of your branches, tills, settings, products, prices and stock, with made-up customers, for staff to practise on. Nothing done there is real or sent anywhere.')
            ->schema([
                Select::make('users')->label('Staff who get a practice login (you always do)')->multiple()->searchable()
                    ->options(fn () => User::where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn ($u) => [$u->id => "{$u->name} ({$u->username})"])),
                Toggle::make('copy_catalogue')->label('Copy products, prices and stock')->default(true)
                    ->helperText('Off, or nothing loaded yet = a small sample range instead.'),
            ])
            ->action(function (array $data) {
                @set_time_limit(1800);
                $r = Erp::run(fn ($actor) => app(PracticeCompany::class)->create($actor, $data['users'] ?? [], (bool) ($data['copy_catalogue'] ?? true)));
                if ($r) {
                    $this->practiceResult = ['name' => $r['tenant']->name, 'logins' => $r['logins'], 'copied' => $r['copied'], 'problems' => $r['problems']];
                    Notification::make()->success()->title('Practice company ready')->send();
                }
            });
    }

    public function archivePracticeAction(): Action
    {
        return Action::make('archivePractice')->label('Archive the practice company')->color('danger')->link()->requiresConfirmation()
            ->modalDescription('Practice logins stop working. You can then create a fresh practice company.')
            ->action(fn () => Erp::run(fn ($actor) => app(PracticeCompany::class)->archive($actor, $this->practice()) ?? true, 'Archived'));
    }

    public function snapshotAction(): Action
    {
        return Action::make('snapshot')->label('Take the go-live backup')->icon(Heroicon::OutlinedCircleStack)->color('gray')->requiresConfirmation()
            ->modalDescription('An encrypted backup of everything as it is now, copied to every backup destination. If going live goes wrong, this is what the technician restores.')
            ->action(function () {
                @set_time_limit(3600);
                $run = Erp::run(fn ($actor) => app(GoLiveService::class)->snapshot($actor));
                if ($run) {
                    Notification::make()->success()->title("Backup taken: {$run->file_name}")->send();
                }
            });
    }

    public function startParallelAction(): Action
    {
        return Action::make('startParallel')->label('Start the parallel run')->icon(Heroicon::OutlinedArrowsRightLeft)->requiresConfirmation()
            ->modalDescription('From today both systems are used. Each evening, enter the old system’s totals; the go-live step turns green after several clean days.')
            ->action(fn () => Erp::run(fn ($actor) => app(GoLiveService::class)->startParallel($actor), 'Parallel run started'));
    }

    public function goLiveAction(): Action
    {
        return Action::make('goLive')->label('Go live')->icon(Heroicon::OutlinedRocketLaunch)->color('success')
            ->modalDescription('From now on this system holds the books and the old one is only for looking things up.')
            ->schema(fn () => array_filter($this->openSteps()) ? [Textarea::make('reason')->label('Some steps are not finished: why go live anyway?')->required()->rows(2)] : [])
            ->action(fn (array $data) => Erp::run(fn ($actor) => app(GoLiveService::class)->goLive($actor, $data['reason'] ?? null), 'Live — congratulations'));
    }

    public function rollBackAction(): Action
    {
        return Action::make('rollBack')->label('Go back to the old system')->color('danger')->icon(Heroicon::OutlinedArrowUturnLeft)
            ->modalDescription('Records the decision. Then follow the rollback plan: the old system takes over selling, and the technician restores the go-live backup if the books must be put back.')
            ->schema([Textarea::make('reason')->label('Why?')->required()->rows(2)])
            ->action(fn (array $data) => Erp::run(fn ($actor) => app(GoLiveService::class)->rollBack($actor, $data['reason']), 'Recorded — follow the rollback plan'));
    }

    public function previewReport(): void
    {
        $this->reportPreview = app(PilotReport::class)->build($this->plan());
    }

    public function sendReport(): void
    {
        $r = app(PilotReport::class)->send($this->plan());
        $this->reportPreview = $r;
        Notification::make()->title($r['sent_to'] ? "Sent to {$r['sent_to']}" : 'Not sent: add the support e-mail in the plan, and check e-mail on System → Messaging')
            ->{$r['sent_to'] ? 'success' : 'warning'}()->send();
    }

    /** @return list<string> titles of required steps not done (excluding going live itself) */
    public function openSteps(): array
    {
        return array_column(array_filter($this->steps(), fn ($s) => ! $s['done'] && ! $s['optional'] && $s['key'] !== 'go_live'), 'title');
    }
}
