<?php

namespace App\Filament\Resources\MaintenanceAssets\RelationManagers;

use App\Domain\Service\Maintenance;
use App\Filament\Support\Erp;
use App\Filament\Support\ServiceUi;
use App\Models\MaintenancePlan;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Servicing that comes round for this equipment: by days, or by its meter. */
class PlansRelationManager extends RelationManager
{
    protected static string $relationship = 'plans';

    protected static ?string $title = 'Servicing plans';

    public function form(Schema $schema): Schema
    {
        $a = $this->getOwnerRecord();

        return $schema->columns(2)->components([
            TextInput::make('name')->label('Servicing')->required()->maxLength(255)->placeholder('e.g. Oil and filter change')->columnSpanFull(),
            TextInput::make('every_days')->label('Every … days')->numeric()->minValue(1)->maxValue(3650),
            TextInput::make('every_meter')->label('Every … '.($a->meter_unit === 'km' ? 'km' : 'running hours'))->numeric()->minValue(1)->visible((bool) $a->meter_unit),
            DatePicker::make('last_done_on')->label('Last done on')->maxDate(now()),
            TextInput::make('lead_days')->label('Open the work order … days early')->numeric()->minValue(0)->maxValue(60)->default(7),
            Select::make('assigned_to')->label('Who does it')->placeholder('—')->options(fn () => ServiceUi::people('maintenance.work')),
            Toggle::make('is_active')->label('In use')->default(true)->inline(false),
            TagsInput::make('checklist')->label('Checklist')->placeholder('Add a step, then Enter')->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        $save = fn (?MaintenancePlan $record, array $data): Model => Erp::run(fn ($actor) => app(Maintenance::class)->savePlan($actor, $this->getOwnerRecord(), $record ?? new MaintenancePlan, $data));

        return $table
            ->columns([
                TextColumn::make('name')->weight('bold'),
                TextColumn::make('every')->label('How often')->state(fn (MaintenancePlan $p) => $p->describe()),
                TextColumn::make('last_done_on')->label('Last done')->date('j M Y')->placeholder('—'),
                TextColumn::make('next_due_on')->label('Next due')->date('j M Y')->placeholder('By the meter')
                    ->color(fn (MaintenancePlan $p) => $p->next_due_on?->isPast() ? 'danger' : null),
                TextColumn::make('assignee.name')->label('Who')->placeholder('—'),
                IconColumn::make('is_active')->boolean()->label('In use'),
            ])
            ->emptyStateHeading('No servicing plans')
            ->emptyStateDescription('Plan regular servicing: a work order opens by itself when it is due.')
            ->headerActions([CreateAction::make()->label('New plan')->visible(fn () => ServiceUi::can('maintenance.manage'))->using(fn (array $data) => $save(null, $data))])
            ->recordActions([EditAction::make()->visible(fn () => ServiceUi::can('maintenance.manage'))->using(fn (MaintenancePlan $record, array $data) => $save($record, $data))]);
    }
}
