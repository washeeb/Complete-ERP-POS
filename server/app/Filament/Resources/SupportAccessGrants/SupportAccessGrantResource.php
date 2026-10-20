<?php

namespace App\Filament\Resources\SupportAccessGrants;

use App\Domain\Remote\RemoteAccess;
use App\Filament\Resources\SupportAccessGrants\Pages\ListSupportAccessGrants;
use App\Filament\Support\Erp;
use App\Models\SupportAccessGrant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** System → Support access: when SyncLytics support may reach this company's devices (owner decision 2026-10-20). */
class SupportAccessGrantResource extends Resource
{
    protected static ?string $model = SupportAccessGrant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Support access';

    protected static ?int $navigationSort = 8;

    public static function canViewAny(): bool
    {
        return Erp::can('support.access.grant') || Erp::can('support.view');
    }

    public static function canCreate(): bool
    {
        return false; // the "Give support access" action, with its own form
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('state')->label('Now')->badge()->state(fn (SupportAccessGrant $r) => match (true) {
                    $r->revoked_at !== null => 'Withdrawn',
                    $r->isActive() => 'Open',
                    default => 'Ended',
                })->color(fn ($state) => match ($state) {
                    'Open' => 'warning', 'Withdrawn' => 'gray', default => 'success'
                }),
                TextColumn::make('level')->label('Support may')->formatStateUsing(fn ($state) => $state === 'control' ? 'See and control' : 'See only'),
                TextColumn::make('branch.name')->label('Devices of')->placeholder('Every branch'),
                TextColumn::make('reason')->label('For')->limit(50),
                TextColumn::make('starts_at')->label('From')->dateTime(),
                TextColumn::make('until')->label('Until')->state(fn (SupportAccessGrant $r) => $r->support_report_id ? 'Problem '.$r->report?->number.' closed' : $r->ends_at?->format('j M Y H:i')),
                TextColumn::make('granted_by_name')->label('Given by'),
                TextColumn::make('revoked_by_name')->label('Withdrawn by')->placeholder('—')->toggleable(),
            ])
            ->recordActions([
                Action::make('revoke')->label('Withdraw now')->icon(Heroicon::OutlinedNoSymbol)->color('danger')->requiresConfirmation()
                    ->visible(fn (SupportAccessGrant $r) => $r->isActive() && Erp::can('support.access.grant'))
                    ->action(fn (SupportAccessGrant $r) => Erp::run(fn ($actor) => app(RemoteAccess::class)->revoke($actor, $r), 'Support access withdrawn')),
            ])
            ->defaultSort('starts_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListSupportAccessGrants::route('/')];
    }
}
