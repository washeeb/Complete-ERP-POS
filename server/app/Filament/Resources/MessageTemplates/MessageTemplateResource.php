<?php

namespace App\Filament\Resources\MessageTemplates;

use App\Domain\Messaging\Templates;
use App\Filament\Resources\MessageTemplates\Pages\ListMessageTemplates;
use App\Filament\Support\Erp;
use App\Models\Message;
use App\Models\MessageTemplate;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** The wording of every SMS, WhatsApp message and e-mail, and whether it is sent at all. */
class MessageTemplateResource extends Resource
{
    protected static ?string $model = MessageTemplate::class;

    protected static ?string $modelLabel = 'message template';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Messages';

    protected static ?string $navigationLabel = 'Templates';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('messaging.templates');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return Erp::canAnywhere('messaging.templates');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Text::make(fn (?MessageTemplate $record) => 'Placeholders you can use: '.(Templates::DEFAULTS[$record?->key][1] ?? '—')),
            Toggle::make('is_active')->label('Send this message'),
            TextInput::make('subject')->maxLength(255)->visible(fn (?MessageTemplate $record) => $record?->channel === 'email'),
            Textarea::make('body')->label('Text')->required()->rows(6),
            TextInput::make('whatsapp_template')->label('Approved WhatsApp template name')->maxLength(80)->visible(fn (?MessageTemplate $record) => $record?->channel === 'whatsapp')
                ->helperText('WhatsApp only delivers business-started messages from templates approved by Meta. Create a template with the same wording there; the placeholders become its {{1}}, {{2}}… in order.'),
            TextInput::make('whatsapp_language')->label('Template language code')->maxLength(10)->visible(fn (?MessageTemplate $record) => $record?->channel === 'whatsapp'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('key')
            ->columns([
                TextColumn::make('key')->label('Message')->formatStateUsing(fn ($state) => Templates::DEFAULTS[$state][0] ?? $state)->searchable(),
                TextColumn::make('channel')->badge()->formatStateUsing(fn ($state) => Message::CHANNELS[$state] ?? $state),
                TextColumn::make('body')->label('Text')->limit(80)->wrap(),
                IconColumn::make('is_active')->label('On')->boolean(),
            ])
            ->filters([SelectFilter::make('channel')->options(Message::CHANNELS)])
            ->recordActions([EditAction::make()->using(fn (MessageTemplate $record, array $data) => Erp::run(fn ($actor) => app(Templates::class)->save($actor, $record, $data)))]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMessageTemplates::route('/')];
    }
}
