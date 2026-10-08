<?php

namespace App\Filament\Resources\SavedReports;

use App\Domain\Reports\ReportEngine;
use App\Domain\Reports\ReportService;
use App\Filament\Pages\ReportBuilder;
use App\Filament\Resources\SavedReports\Pages\ListSavedReports;
use App\Filament\Support\Erp;
use App\Models\SavedReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Reports saved from the report builder (yours, and others' shared ones) and their schedules. */
class SavedReportResource extends Resource
{
    protected static ?string $model = SavedReport::class;

    protected static ?string $modelLabel = 'saved report';

    protected static ?string $slug = 'saved-reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookmark;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Saved reports';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('reports.build');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $me = Erp::user()?->id;

        return parent::getEloquentQuery()->where(fn ($q) => $q->where('owner_id', $me)->orWhere('shared', true));
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')
            ->recordUrl(fn (SavedReport $r) => ReportBuilder::getUrl(['report' => $r->id]))
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('dataset')->label('Data')->formatStateUsing(fn ($state) => ReportEngine::datasets()[$state][0] ?? $state),
                TextColumn::make('owner_name')->label('Saved by'),
                TextColumn::make('schedule')->formatStateUsing(fn ($state, SavedReport $r) => SavedReport::SCHEDULES[$state] ?? $state)
                    ->description(fn (SavedReport $r) => $r->schedule === 'none' ? null : 'Next: '.$r->next_run_at?->setTimezone(Erp::user()->tenant->timezone ?: 'UTC')->format('D j M H:i')),
                TextColumn::make('last_status')->label('Last sent')->placeholder('—')->wrap(),
            ])
            ->recordActions([
                Action::make('delete')->label('Delete')->color('danger')->requiresConfirmation()->visible(fn (SavedReport $r) => $r->owner_id === Erp::user()?->id)
                    ->action(fn (SavedReport $record) => Erp::run(fn ($actor) => app(ReportService::class)->delete($actor, $record) ?? true, 'Deleted')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSavedReports::route('/')];
    }
}
