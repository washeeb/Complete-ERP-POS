<?php

namespace App\Filament\Resources\Computers;

use App\Domain\Support\Recorder;
use App\Filament\Resources\AuditEvents\AuditEventResource;
use App\Filament\Resources\Computers\Pages\ListComputers;
use App\Filament\Support\Erp;
use App\Models\Computer;
use App\Support\ClientInfo;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * System → Computers: every computer (browser) that used the back office, with who used it last and from which address.
 * Name each one ("Front desk PC") so the audit log says which computer did what.
 */
class ComputerResource extends Resource
{
    protected static ?string $model = Computer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Computers';

    protected static ?int $navigationSort = 6;

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('audit.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Computer')->state(fn (Computer $r) => $r->label())->weight('bold')
                    ->description(fn (Computer $r) => trim(($r->last_client['computer'] ?? '').' '.($r->last_client['mac'] ?? '')) ?: null)
                    ->searchable(['name']),
                TextColumn::make('last_user_name')->label('Last used by')->placeholder('—'),
                TextColumn::make('last_seen_at')->label('Last used')->since()->sortable(),
                TextColumn::make('last_ip')->label('From address')->placeholder('—')
                    ->url(fn (Computer $r) => ClientInfo::lookupUrl($r->last_ip), shouldOpenInNewTab: true)
                    ->description(fn (Computer $r) => ClientInfo::lookupUrl($r->last_ip) ? 'Where is this? (opens ipinfo.io)' : null),
                TextColumn::make('last_client.browser')->label('Browser')->placeholder('—')->toggleable(),
                TextColumn::make('first_seen_at')->label('First used')->date()->sortable()->toggleable(),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->recordActions([
                Action::make('rename')->label('Name it')->icon(Heroicon::OutlinedPencilSquare)
                    ->schema([TextInput::make('name')->label('Name (e.g. Front desk PC)')->required()->maxLength(80)->autocomplete('off')])
                    ->fillForm(fn (Computer $r) => ['name' => $r->name])
                    ->action(fn (Computer $r, array $data) => Erp::run(fn ($actor) => app(Recorder::class)->save($actor, $r->fill(['name' => trim($data['name'])]), 'computer.named'))),
                Action::make('activity')->label('What it did')->icon(Heroicon::OutlinedListBullet)
                    ->url(fn (Computer $r) => AuditEventResource::getUrl('index', ['filters' => ['computer' => ['value' => $r->computer_key]]])),
            ])
            ->emptyStateHeading('No computers yet')
            ->emptyStateDescription('Each computer is listed the first time someone signs in to the back office from it.');
    }

    public static function getPages(): array
    {
        return ['index' => ListComputers::route('/')];
    }
}
