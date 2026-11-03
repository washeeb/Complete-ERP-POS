<?php

namespace App\Filament\Resources\IotSensors\RelationManagers;

use App\Models\IotSensor;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Signed checks of the sensor's fridge or room: who looked, when, what the display showed. */
class ChecksRelationManager extends RelationManager
{
    protected static string $relationship = 'checks';

    protected static ?string $title = 'Checks';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof IotSensor && $ownerRecord->measuresTemperature();
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('checked_at')->label('When')->dateTime('j M Y H:i')->sortable(),
                TextColumn::make('user_name')->label('By'),
                TextColumn::make('seen_temp')->label('Display showed')->placeholder('—')->formatStateUsing(fn ($state) => $state === null ? null : rtrim(rtrim((string) $state, '0'), '.').' °C'),
                TextColumn::make('ok')->label('All well')->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No')->color(fn ($state) => $state ? 'success' : 'danger'),
                TextColumn::make('note')->wrap()->placeholder('—'),
            ])
            ->defaultSort('checked_at', 'desc')
            ->emptyStateHeading('No checks yet')
            ->emptyStateDescription('“Record a check” at the top: what the fridge display shows, signed with your name (most pharmacies check morning and afternoon).');
    }
}
