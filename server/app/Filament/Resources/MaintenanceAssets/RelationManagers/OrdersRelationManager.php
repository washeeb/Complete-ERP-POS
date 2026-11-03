<?php

namespace App\Filament\Resources\MaintenanceAssets\RelationManagers;

use App\Filament\Resources\MaintenanceOrders\MaintenanceOrderResource;
use App\Filament\Support\FinanceUi;
use App\Models\MaintenanceOrder;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** The equipment's history: every work order, with its cost and downtime. */
class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $title = 'Work orders';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('No.')->color('gray'),
                TextColumn::make('title')->weight('bold')->wrap()->description(fn (MaintenanceOrder $o) => MaintenanceOrder::KINDS[$o->kind] ?? $o->kind),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => MaintenanceOrder::STATUSES[$state] ?? $state),
                TextColumn::make('cost')->label('Cost')->alignEnd()->state(fn (MaintenanceOrder $o) => FinanceUi::money($o->totalMinor())),
                TextColumn::make('down')->label('Down for')->state(fn (MaintenanceOrder $o) => $o->down_from ? self::span($o->downtimeMinutes()) : '—'),
                TextColumn::make('done_at')->label('Done')->date('j M Y')->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (MaintenanceOrder $o) => MaintenanceOrderResource::getUrl('view', ['record' => $o]))
            ->emptyStateHeading('No work orders yet');
    }

    public static function span(int $minutes): string
    {
        return $minutes >= 1440 ? round($minutes / 1440, 1).' day(s)' : intdiv($minutes, 60).' h '.($minutes % 60).' min';
    }
}
