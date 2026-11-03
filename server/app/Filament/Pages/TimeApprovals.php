<?php

namespace App\Filament\Pages;

use App\Domain\Projects\Timesheets;
use App\Filament\Support\Erp;
use App\Filament\Support\ProjectUi;
use App\Models\TimeEntry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/** Projects → Time to approve: weeks people sent, by person; approve them (they become project costs) or send them back. */
class TimeApprovals extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|UnitEnum|null $navigationGroup = 'Projects';

    protected static ?string $navigationLabel = 'Time to approve';

    protected static ?string $title = 'Time to approve';

    protected static ?string $slug = 'time-approvals';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.table-page';

    public static function canAccess(): bool
    {
        return ProjectUi::can('projects.approve');
    }

    public static function getNavigationBadge(): ?string
    {
        if (! self::canAccess()) {
            return null;
        }
        $n = self::waiting()->distinct('user_id')->count('user_id');

        return $n ? (string) $n : null;
    }

    public function getSubheading(): ?string
    {
        return 'Hours people sent for approval. Approved hours become project costs (at each person’s cost rate) and billable time. Your own time is approved by someone else.';
    }

    /** Sent time on projects of the branches the person approves for, not their own. */
    public static function waiting(): Builder
    {
        $b = Erp::branchIds('projects.approve');

        return TimeEntry::query()->where('status', 'submitted')->where('user_id', '<>', Erp::user()?->id)
            ->when($b !== null, fn ($q) => $q->whereHas('project', fn ($p) => $p->whereIn('branch_id', $b)));
    }

    public function table(Table $table): Table
    {
        $sheets = app(Timesheets::class);
        $reason = [TextInput::make('reason')->label('What to correct')->required()->maxLength(255)];

        return $table
            ->query(fn () => self::waiting()->with(['user', 'project', 'task']))
            ->columns([
                TextColumn::make('work_date')->label('Day')->date('D j M')->sortable(),
                TextColumn::make('project.name')->label('Project')->description(fn (TimeEntry $e) => $e->task?->name),
                TextColumn::make('note')->label('What')->wrap()->placeholder('—'),
                TextColumn::make('minutes')->label('Hours')->alignEnd()->formatStateUsing(fn ($state) => TimeEntry::hours((int) $state))
                    ->summarize(Sum::make()->formatStateUsing(fn ($state) => TimeEntry::hours((int) $state))->label('')),
                TextColumn::make('billable')->label('Billed')->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No'),
            ])
            ->groups([Group::make('user.name')->label('Person')->collapsible()])
            ->defaultGroup('user.name')
            ->defaultSort('work_date')
            ->emptyStateHeading('Nothing to approve')
            ->recordActions([
                Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->action(fn (TimeEntry $record) => Erp::run(fn ($actor) => $sheets->approve($actor, [$record]), 'Approved')),
                Action::make('reject')->label('Send back')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')->schema($reason)
                    ->action(fn (TimeEntry $record, array $data) => Erp::run(fn ($actor) => $sheets->reject($actor, [$record], $data['reason']), 'Sent back')),
            ])
            ->toolbarActions([
                BulkAction::make('approveMany')->label('Approve selected')->icon(Heroicon::OutlinedCheck)->color('success')->requiresConfirmation()
                    ->action(fn (Collection $records) => Erp::run(fn ($actor) => $sheets->approve($actor, $records), 'Approved')),
                BulkAction::make('rejectMany')->label('Send back selected')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')->schema($reason)
                    ->action(fn (Collection $records, array $data) => Erp::run(fn ($actor) => $sheets->reject($actor, $records, $data['reason']), 'Sent back')),
            ]);
    }
}
