<?php

namespace App\Filament\Resources\SupportReports;

use App\Domain\Launch\SupportReports;
use App\Filament\Resources\SupportReports\Pages\ListSupportReports;
use App\Filament\Support\Erp;
use App\Models\SupportReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Problems staff reported from the back office or a till ("Report a problem"), for support to follow up and close. */
class SupportReportResource extends Resource
{
    protected static ?string $model = SupportReport::class;

    protected static ?string $modelLabel = 'problem report';

    protected static ?string $slug = 'problem-reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Problem reports';

    protected static ?int $navigationSort = 7;

    public static function canViewAny(): bool
    {
        return Erp::can('support.view');
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

    public static function getNavigationBadge(): ?string
    {
        $n = SupportReport::where('status', 'open')->count();

        return $n ? (string) $n : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextEntry::make('number'), TextEntry::make('created_at')->label('When')->dateTime('j M Y H:i'), TextEntry::make('user_name')->asPerson('user_id')->label('From'),
            TextEntry::make('source')->formatStateUsing(fn ($state) => SupportReport::SOURCES[$state] ?? $state), TextEntry::make('page')->placeholder('—'),
            TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => SupportReport::STATUSES[$state] ?? $state),
            TextEntry::make('message')->label('What happened')->columnSpanFull()->extraAttributes(['style' => 'white-space:pre-wrap']),
            TextEntry::make('resolution')->placeholder('—')->columnSpanFull(),
            TextEntry::make('send_error')->label('E-mail to support')->placeholder('Sent')->columnSpanFull(),
            TextEntry::make('context')->label('Details for support')->columnSpanFull()
                ->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                ->extraAttributes(['style' => 'white-space:pre-wrap;font-family:monospace;font-size:.75rem']),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('created_at')->label('When')->since()->sortable(),
                TextColumn::make('user_name')->asPerson('user_id')->label('From')->searchable()->description(fn (SupportReport $r) => SupportReport::SOURCES[$r->source] ?? $r->source),
                TextColumn::make('message')->label('What happened')->limit(80)->wrap()->searchable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => SupportReport::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => $state === 'open' ? 'warning' : 'success')->description(fn (SupportReport $r) => $r->resolution),
            ])
            ->filters([SelectFilter::make('status')->options(SupportReport::STATUSES)->default('open'), SelectFilter::make('source')->options(SupportReport::SOURCES)])
            ->recordActions([
                ViewAction::make(),
                Action::make('close')->label('Close')->icon(Heroicon::OutlinedCheck)->visible(fn (SupportReport $r) => $r->status === 'open')
                    ->schema([TextInput::make('resolution')->label('What was done')->required()->maxLength(255)])
                    ->action(fn (SupportReport $record, array $data) => Erp::run(fn ($actor) => app(SupportReports::class)->close($actor, $record, $data['resolution']), 'Closed')),
                Action::make('resend')->label('Send again')->icon(Heroicon::OutlinedPaperAirplane)->visible(fn (SupportReport $r) => $r->send_error !== null)
                    ->action(fn (SupportReport $record) => Notification::make()->title(app(SupportReports::class)->send($record) ? 'Sent to support' : 'Still not sent: '.$record->fresh()->send_error)->send()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSupportReports::route('/')];
    }
}
