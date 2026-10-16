<?php

namespace App\Filament\Resources\AuditEvents;

use App\Filament\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Filament\Support\Erp;
use App\Models\AuditEvent;
use App\Models\Computer;
use App\Support\ClientInfo;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class AuditEventResource extends Resource
{
    protected static ?string $model = AuditEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?int $navigationSort = 9;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('audit.view');
    }

    public static function canView(Model $record): bool
    {
        return Erp::can('audit.view') || ($record->branch_id && in_array($record->branch_id, Erp::branchIds('audit.view') ?? [], true));
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
        // Branch-scoped viewers see their branches' rows only; company-wide rows need a company-wide grant.
        $ids = Erp::branchIds('audit.view');

        return parent::getEloquentQuery()->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                TextEntry::make('seq')->label('#'),
                TextEntry::make('occurred_at')->dateTime(),
                TextEntry::make('action'),
                TextEntry::make('actor_name')->asPerson('actor_id')->label('By'),
                TextEntry::make('source'),
                TextEntry::make('origin_node')->label('Node'),
                TextEntry::make('subject_type')->label('Record'),
                TextEntry::make('subject_id')->label('Record id')->copyable(),
                TextEntry::make('ip')->label('IP address')->placeholder('—')->url(fn (AuditEvent $r) => ClientInfo::lookupUrl($r->ip), shouldOpenInNewTab: true)
                    ->helperText(fn (AuditEvent $r) => ClientInfo::lookupUrl($r->ip) ? 'Click to see roughly where this address is (opens ipinfo.io).' : null),
                TextEntry::make('client.computer_key')->label('Computer (System → Computers)')->placeholder('—')
                    ->formatStateUsing(fn (?string $state) => $state ? (Computer::labels()[$state] ?? 'Not listed') : null),
                TextEntry::make('client.computer')->label('Computer name')->placeholder('— (browser: not available)'),
                TextEntry::make('client.mac')->label('MAC address')->placeholder('— (browser: not available)')->fontFamily('mono'),
                TextEntry::make('client.local_ip')->label('Address on the shop network')->placeholder('—'),
                TextEntry::make('client.browser')->label('Browser and system')->placeholder('—'),
                TextEntry::make('client.app')->label('App')->placeholder('—'),
                TextEntry::make('reason')->placeholder('—')->columnSpanFull(),
                KeyValueEntry::make('changes_flat')->label('Changes (before → after)')->columnSpanFull()
                    ->state(fn (AuditEvent $r) => collect($r->changes)->map(fn ($pair) => is_array($pair) && count($pair) === 2
                        ? json_encode($pair[0], JSON_UNESCAPED_UNICODE).' → '.json_encode($pair[1], JSON_UNESCAPED_UNICODE)
                        : json_encode($pair))->all()),
                TextEntry::make('hash')->fontFamily('mono')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('seq')->label('#')->sortable(),
                TextColumn::make('occurred_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('actor_name')->asPerson('actor_id')->label('By')->searchable(),
                TextColumn::make('action')->badge()->searchable(),
                TextColumn::make('subject_type')->label('Record'),
                TextColumn::make('source'),
                TextColumn::make('client')->label('Computer')->placeholder('—')->wrap()->toggleable()
                    ->state(fn (AuditEvent $r) => ClientInfo::summary($r->client)),
                TextColumn::make('ip')->label('IP address')->placeholder('—')->searchable()->toggleable(),
                TextColumn::make('reason')->limit(40)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('source')->options(['web' => 'Back office', 'pos' => 'POS', 'api' => 'API', 'cli' => 'Command line', 'system' => 'System', 'sync' => 'Sync']),
                SelectFilter::make('computer')->label('Computer')->options(fn () => Computer::labels())
                    ->query(fn (Builder $q, array $data) => filled($data['value'] ?? null) ? $q->whereRaw("client->>'computer_key' = ?", [$data['value']]) : $q),
                SelectFilter::make('signins')->label('Sign-ins')->options(['auth' => 'Back-office sign-ins and sign-outs', 'pos' => 'Till sign-ins'])
                    ->query(fn (Builder $q, array $data) => match ($data['value'] ?? null) {
                        'auth' => $q->where('action', 'like', 'auth.%'),
                        'pos' => $q->whereIn('action', ['pos.login', 'pos.login_failed', 'pos.pin_locked']),
                        default => $q,
                    }),
            ])
            ->defaultSort('seq', 'desc')
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditEvents::route('/')];
    }
}
