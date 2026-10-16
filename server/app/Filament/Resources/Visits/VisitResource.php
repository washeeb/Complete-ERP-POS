<?php

namespace App\Filament\Resources\Visits;

use App\Domain\Modules\ModuleService;
use App\Filament\Resources\Visits\Pages\ListVisits;
use App\Filament\Support\Erp;
use App\Models\Customer;
use App\Models\SalesRoute;
use App\Models\Visit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Visits recorded by reps on the road (outcome, where and when). Read-only. */
class VisitResource extends Resource
{
    protected static ?string $model = Visit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Distribution';

    protected static ?string $navigationLabel = 'Visits';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('customers.view') && ModuleService::enabledAnywhere('distribution');
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
        $ids = Erp::branchIds('customers.view');

        return parent::getEloquentQuery()->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime('D j M, H:i')->sortable(),
                TextColumn::make('user_name')->asPerson('user_id')->label('Rep')->searchable(),
                TextColumn::make('customer_id')->label('Customer')->formatStateUsing(fn ($state) => Customer::whereKey($state)->value('name')),
                TextColumn::make('route_id')->label('Route')->formatStateUsing(fn ($state) => SalesRoute::whereKey($state)->value('code'))->placeholder('—'),
                TextColumn::make('outcome')->badge()->formatStateUsing(fn ($state) => Visit::OUTCOMES[$state] ?? $state)
                    ->color(fn ($state) => ['sold' => 'success', 'order' => 'info', 'closed' => 'gray'][$state] ?? 'warning'),
                TextColumn::make('note')->placeholder('—')->wrap(),
                TextColumn::make('lat')->label('Location')->placeholder('No GPS')
                    ->formatStateUsing(fn ($state, Visit $r) => $r->lat === null ? null : sprintf('%.5f, %.5f (±%d m)', $r->lat, $r->lng, (int) $r->accuracy_m))
                    ->url(fn (Visit $r) => $r->lat === null ? null : "https://www.openstreetmap.org/?mlat={$r->lat}&mlon={$r->lng}#map=17/{$r->lat}/{$r->lng}", true),
            ])
            ->filters([
                SelectFilter::make('outcome')->options(Visit::OUTCOMES),
                Filter::make('today')->label('Today')->query(fn (Builder $query) => $query->where('occurred_at', '>=', now()->startOfDay())),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListVisits::route('/')];
    }
}
