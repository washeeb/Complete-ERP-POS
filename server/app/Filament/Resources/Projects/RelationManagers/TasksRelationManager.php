<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Domain\Projects\Projects;
use App\Filament\Support\Erp;
use App\Filament\Support\ProjectUi;
use App\Models\ProjectTask;
use App\Models\TimeEntry;
use App\Models\User;
use App\Security\Authorizer;
use App\Support\IndustryContext;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** A project's tasks: who does them, by when, the hours planned and logged. */
class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $title = 'Tasks';

    public function form(Schema $schema): Schema
    {
        $p = $this->getOwnerRecord();

        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255)->columnSpanFull(),
            Select::make('assigned_to')->label('Who does it')->placeholder('—')
                ->options(fn () => IndustryContext::userScope(User::where('is_active', true))->orderBy('name')->get()->filter(fn ($u) => app(Authorizer::class)->allows($u, 'projects.time', $p->branch))->pluck('name', 'id')),
            Select::make('status')->options(ProjectTask::STATUSES)->default('todo')->selectablePlaceholder(false),
            DatePicker::make('due_on')->label('Due'),
            TextInput::make('estimate_hours')->label('Hours planned')->numeric()->minValue(0),
            Toggle::make('billable')->label('Time on it is billed')->default(true)->visible($p->isBilled()),
            TextInput::make('sort')->label('Order')->numeric()->default(0),
        ]);
    }

    public function table(Table $table): Table
    {
        $save = fn (?ProjectTask $record, array $data): Model => Erp::run(fn ($actor) => app(Projects::class)->saveTask($actor, $this->getOwnerRecord(), $record ?? new ProjectTask, $data));
        $logged = fn (ProjectTask $t) => (int) TimeEntry::where('task_id', $t->id)->sum('minutes');

        return $table
            ->columns([
                TextColumn::make('name')->weight('bold')->wrap(),
                TextColumn::make('assignee.name')->label('Who')->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => ProjectTask::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'done' => 'success', 'doing' => 'info', default => 'gray'
                    }),
                TextColumn::make('due_on')->label('Due')->date('j M')->placeholder('—')
                    ->color(fn (ProjectTask $t) => $t->status !== 'done' && $t->due_on?->isPast() ? 'danger' : null),
                TextColumn::make('hours')->label('Hours')->alignEnd()
                    ->state(fn (ProjectTask $t) => TimeEntry::hours($logged($t)).($t->estimate_hours ? ' of '.rtrim(rtrim((string) $t->estimate_hours, '0'), '.') : '')),
            ])
            ->defaultSort('sort')
            ->emptyStateHeading('No tasks')
            ->headerActions([CreateAction::make()->label('New task')->visible(fn () => $this->getOwnerRecord()->isOpen() && ProjectUi::can('projects.manage'))
                ->using(fn (array $data) => $save(null, $data))])
            ->recordActions([EditAction::make()->visible(fn () => $this->getOwnerRecord()->isOpen() && ProjectUi::can('projects.manage'))
                ->using(fn (ProjectTask $record, array $data) => $save($record, $data))]);
    }
}
