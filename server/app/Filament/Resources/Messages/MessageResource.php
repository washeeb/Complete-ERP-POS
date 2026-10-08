<?php

namespace App\Filament\Resources\Messages;

use App\Filament\Resources\Messages\Pages\ListMessages;
use App\Filament\Support\Erp;
use App\Models\Message;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Every SMS, WhatsApp message and e-mail sent (or not): to whom, what, and how delivery went. */
class MessageResource extends Resource
{
    protected static ?string $model = Message::class;

    protected static ?string $slug = 'message-log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Messages';

    protected static ?string $navigationLabel = 'Message log';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('messaging.view');
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

    public static function colour(string $status): string
    {
        return ['sent' => 'success', 'failed' => 'danger', 'queued' => 'warning'][$status] ?? 'gray';
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextEntry::make('created_at')->label('When')->dateTime('j M Y H:i'),
            TextEntry::make('channel')->formatStateUsing(fn ($state) => Message::CHANNELS[$state] ?? $state),
            TextEntry::make('to'),
            TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => Message::STATUSES[$state] ?? $state)->color(fn ($state) => self::colour($state)),
            TextEntry::make('provider')->placeholder('—')->belowContent(fn (Message $r) => $r->provider_ref),
            TextEntry::make('attempts')->label('Tries'),
            TextEntry::make('subject')->placeholder('—')->columnSpanFull(),
            TextEntry::make('body')->label('Text')->columnSpanFull()->extraAttributes(['style' => 'white-space:pre-wrap']),
            TextEntry::make('attachment_name')->label('Attachment')->placeholder('—'),
            TextEntry::make('error')->placeholder('—')->columnSpan(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('j M H:i')->sortable(),
                TextColumn::make('channel')->badge()->formatStateUsing(fn ($state) => Message::CHANNELS[$state] ?? $state),
                TextColumn::make('to')->searchable(),
                TextColumn::make('reference')->placeholder('—')->searchable(),
                TextColumn::make('body')->label('Text')->limit(60),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => Message::STATUSES[$state] ?? $state)->color(fn ($state) => self::colour($state))
                    ->description(fn (Message $r) => $r->status === 'sent' ? null : $r->error),
            ])
            ->filters([SelectFilter::make('status')->options(Message::STATUSES), SelectFilter::make('channel')->options(Message::CHANNELS)])
            ->recordActions([
                ViewAction::make(),
                Action::make('retry')->label('Try again')->icon(Heroicon::OutlinedArrowPath)->visible(fn (Message $r) => in_array($r->status, ['failed', 'not_configured'], true))
                    ->action(function (Message $record) {
                        $record->forceFill(['status' => 'queued', 'attempts' => 0, 'next_attempt_at' => now(), 'error' => null])->save(); // the relay sends it within seconds
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMessages::route('/')];
    }
}
