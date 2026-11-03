<?php

namespace App\Filament\Pages;

use App\Domain\Projects\Timesheets;
use App\Filament\Support\Erp;
use App\Filament\Support\ProjectUi;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\TimeEntry;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Projects → My time: the person's week — a start / stop timer, the hours logged day by day on projects and tasks —
 * then "Send this week" for a manager's approval. Entries sent back come with the reason and can be corrected.
 */
class MyTime extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Projects';

    protected static ?string $navigationLabel = 'My time';

    protected static ?string $title = 'My time';

    protected static ?string $slug = 'my-time';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.my-time';

    #[Url]
    public string $week = '';

    public static function canAccess(): bool
    {
        return ProjectUi::can('projects.time');
    }

    public function mount(): void
    {
        $this->week = Timesheets::week($this->week ?: now()->toDateString())[0];
    }

    public function getSubheading(): ?string
    {
        [$from, $to] = Timesheets::week($this->week);

        return 'Week of '.CarbonImmutable::parse($from)->format('j M').' – '.CarbonImmutable::parse($to)->format('j M Y').' · '
            .TimeEntry::hours((int) $this->entries()->sum('minutes')).' hour(s). Log your hours, then “Send this week” for approval.';
    }

    public function running(): ?TimeEntry
    {
        return TimeEntry::with(['project', 'task'])->where('user_id', Erp::user()?->id)->whereNotNull('timer_started_at')->first();
    }

    private function entries()
    {
        [$from, $to] = Timesheets::week($this->week);

        return TimeEntry::query()->where('user_id', Erp::user()?->id)->whereBetween('work_date', [$from, $to]);
    }

    /** @return array<string, string> open projects the person may log time on */
    public static function projects(): array
    {
        $b = Erp::branchIds('projects.time'); // people who log time need not see the projects pages

        return Project::query()->whereIn('status', ['planned', 'active', 'on_hold'])->when($b !== null, fn ($q) => $q->whereIn('branch_id', $b))
            ->orderBy('number')->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->number} {$p->name}"])->all();
    }

    private static function taskField(): Select
    {
        return Select::make('task_id')->label('Task')->placeholder('—')
            ->options(fn (Get $get) => $get('project_id') ? ProjectTask::where('project_id', $get('project_id'))->where('status', '<>', 'done')->orderBy('sort')->pluck('name', 'id') : []);
    }

    public function table(Table $table): Table
    {
        $sheets = app(Timesheets::class);
        $editable = fn (TimeEntry $e) => in_array($e->status, ['draft', 'rejected'], true) && ! $e->timer_started_at;
        $form = [
            Select::make('project_id')->label('Project')->options(fn () => self::projects())->required()->live()->searchable(),
            self::taskField(),
            DatePicker::make('work_date')->label('Day')->required()->default(now())->maxDate(now()),
            TextInput::make('hours')->label('Hours')->numeric()->minValue(0.05)->maxValue(24)->required()->helperText('e.g. 1.5 for an hour and a half'),
            TextInput::make('note')->label('What you did')->maxLength(255),
        ];

        return $table
            ->query(fn () => $this->entries()->with(['project', 'task']))
            ->columns([
                TextColumn::make('work_date')->label('Day')->date('D j M')->sortable(),
                TextColumn::make('project.name')->label('Project')->description(fn (TimeEntry $e) => $e->task?->name),
                TextColumn::make('note')->label('What')->wrap()->placeholder('—'),
                TextColumn::make('minutes')->label('Hours')->alignEnd()->formatStateUsing(fn ($state, TimeEntry $e) => $e->timer_started_at ? 'Running…' : TimeEntry::hours((int) $state)),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => TimeEntry::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success', 'submitted' => 'info', 'rejected' => 'danger', default => 'gray'
                    })
                    ->description(fn (TimeEntry $e) => $e->reject_reason),
            ])
            ->defaultSort('work_date')
            ->paginated(false)
            ->emptyStateHeading('No time this week')
            ->emptyStateDescription('Add your hours, or start the timer when you begin a task.')
            ->headerActions([
                Action::make('add')->label('Add time')->icon(Heroicon::OutlinedPlus)->schema($form)
                    ->action(fn (array $data) => Erp::run(fn ($actor) => $sheets->log($actor, new TimeEntry, $data), 'Time added')),
            ])
            ->recordActions([
                Action::make('edit')->label('Change')->icon(Heroicon::OutlinedPencilSquare)->color('gray')->visible($editable)->schema($form)
                    ->fillForm(fn (TimeEntry $e) => ['project_id' => $e->project_id, 'task_id' => $e->task_id, 'work_date' => $e->work_date, 'hours' => round($e->minutes / 60, 2), 'note' => $e->note])
                    ->action(fn (TimeEntry $record, array $data) => Erp::run(fn ($actor) => $sheets->log($actor, $record, $data), 'Changed')),
                Action::make('remove')->label('Remove')->icon(Heroicon::OutlinedTrash)->color('danger')->requiresConfirmation()->visible($editable)
                    ->action(fn (TimeEntry $record) => Erp::run(fn ($actor) => $sheets->delete($actor, $record), 'Removed')),
            ]);
    }

    protected function getHeaderActions(): array
    {
        $sheets = app(Timesheets::class);

        return [
            Action::make('previous')->label('Previous week')->icon(Heroicon::OutlinedChevronLeft)->color('gray')
                ->action(fn () => $this->week = CarbonImmutable::parse($this->week)->subWeek()->toDateString()),
            Action::make('next')->label('Next week')->icon(Heroicon::OutlinedChevronRight)->color('gray')
                ->visible(fn () => CarbonImmutable::parse($this->week)->addWeek()->isBefore(now()))
                ->action(fn () => $this->week = CarbonImmutable::parse($this->week)->addWeek()->toDateString()),
            Action::make('start')->label('Start timer')->icon(Heroicon::OutlinedPlay)->color('success')->visible(fn () => ! $this->running())
                ->schema([Select::make('project_id')->label('Project')->options(fn () => self::projects())->required()->live()->searchable(), self::taskField(),
                    TextInput::make('note')->label('What you are doing')->maxLength(255)])
                ->action(fn (array $data) => Erp::run(fn ($actor) => $sheets->start($actor, Project::findOrFail($data['project_id']),
                    ($data['task_id'] ?? null) ? ProjectTask::find($data['task_id']) : null, $data['note'] ?? null), 'Timer started')),
            Action::make('stop')->label('Stop timer')->icon(Heroicon::OutlinedStop)->color('danger')->visible(fn () => (bool) $this->running())
                ->action(fn () => Erp::run(fn ($actor) => $sheets->stop($actor), 'Timer stopped')),
            Action::make('submit')->label('Send this week')->icon(Heroicon::OutlinedPaperAirplane)->requiresConfirmation()
                ->visible(fn () => $this->entries()->whereIn('status', ['draft', 'rejected'])->exists())
                ->modalDescription('Your hours this week go to your manager for approval; you cannot change them unless they are sent back.')
                ->action(fn () => Erp::run(fn ($actor) => $sheets->submitWeek($actor, $this->week), 'Sent for approval')),
        ];
    }
}
