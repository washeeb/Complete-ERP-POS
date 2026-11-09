<?php

namespace App\Filament\Resources\CrmCampaigns;

use App\Domain\Crm\Crm;
use App\Filament\Resources\CrmCampaigns\Pages\CreateCrmCampaign;
use App\Filament\Resources\CrmCampaigns\Pages\EditCrmCampaign;
use App\Filament\Resources\CrmCampaigns\Pages\ListCrmCampaigns;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\IndustryField;
use App\Models\Branch;
use App\Models\CrmCampaign;
use App\Models\Customer;
use App\Models\Message;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * CRM → Campaigns: one message to many customers (and leads) by SMS, WhatsApp or e-mail, chosen by tags, branch, when
 * they last bought and how much they spent — sent only to those who agreed to that channel.
 */
class CrmCampaignResource extends Resource
{
    protected static ?string $model = CrmCampaign::class;

    protected static ?string $slug = 'crm-campaigns';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'CRM';

    protected static ?string $navigationLabel = 'Campaigns';

    protected static ?string $modelLabel = 'campaign';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return Crm::enabled() && (Erp::canAnywhere('crm.campaigns') || Erp::canAnywhere('crm.view'));
    }

    public static function canCreate(): bool
    {
        return Crm::enabled() && Erp::canAnywhere('crm.campaigns');
    }

    public static function canEdit(Model $record): bool
    {
        return self::canCreate() && in_array($record->status, ['draft', 'scheduled'], true);
    }

    public static function canDelete(Model $record): bool
    {
        return false; // cancelled, kept with what was sent
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Message')->columns(2)->schema([
                TextInput::make('name')->label('Campaign name (for you)')->required()->maxLength(255)->placeholder('e.g. December offers'),
                IndustryField::make(),
                Radio::make('channel')->label('Send by')->options(CrmCampaign::CHANNELS)->default('sms')->inline()->required()->live(),
                TextInput::make('subject')->label('E-mail subject')->maxLength(255)->visible(fn (Get $get) => $get('channel') === 'email')->required(fn (Get $get) => $get('channel') === 'email'),
                TextInput::make('whatsapp_template')->label('WhatsApp template name')->maxLength(80)->visible(fn (Get $get) => $get('channel') === 'whatsapp')
                    ->required(fn (Get $get) => $get('channel') === 'whatsapp')
                    ->helperText('WhatsApp only delivers business messages that use a template approved by Meta. Type its name; the text below must match it.'),
                Textarea::make('body')->label('Message')->required()->rows(5)->columnSpanFull()->live(onBlur: true)
                    ->helperText(fn (Get $get) => '{customer} becomes the person’s name, {company} your company’s.'.($get('channel') === 'sms' ? ' '.mb_strlen((string) $get('body')).' of 480 characters.' : '')
                        .($get('channel') === 'email' ? ' A link to stop these e-mails is added at the end.' : '')),
            ]),
            Section::make('Who gets it')->description('Only people who agreed to this channel and have a phone number or e-mail address get it; the count shows how many.')
                ->columns(3)->schema([
                    TagsInput::make('audience.tags')->label('Customers with any of these tags')->placeholder('e.g. VIP, wholesale')
                        ->suggestions(fn () => Customer::whereNotNull('tags')->pluck('tags')->flatten()->filter()->unique()->values()->all())->live(),
                    Select::make('audience.branch_id')->label('Home branch')->placeholder('All branches')->options(fn () => Branch::where('is_active', true)->orderBy('code')->pluck('name', 'id'))->live(),
                    TextInput::make('audience.min_spent')->label('Spent at least (last 12 months)')->numeric()->minValue(0)->prefix(fn () => FinanceUi::currency())->live(onBlur: true),
                    TextInput::make('audience.bought_within_days')->label('Bought in the last … days')->numeric()->minValue(1)->live(onBlur: true),
                    TextInput::make('audience.not_bought_days')->label('Not back for … days (win back)')->numeric()->minValue(1)->live(onBlur: true),
                    Toggle::make('audience.include_leads')->label('Also open leads who agreed')->inline(false)->live(),
                    Text::make(fn (Get $get) => self::reach($get))->columnSpanFull(),
                ]),
            Section::make('When')->schema([
                DateTimePicker::make('send_at')->label('Send at (leave empty to send when you press “Send”)')->seconds(false)->minDate(now())
                    ->helperText('Mind the time: messages at night annoy people.'),
            ]),
        ]);
    }

    /** "Reaches 120 of 340 picked." for the audience being chosen. */
    private static function reach(Get $get): string
    {
        $c = new CrmCampaign(['channel' => $get('channel') ?: 'sms', 'audience' => self::audience((array) $get('audience'))]);
        $r = app(Crm::class)->preview($c);
        $picked = $r['customers'] + $r['leads'];

        return "Reaches {$r['reachable']} of the {$picked} picked by these choices.".($picked > $r['reachable'] ? ' The others did not agree to '.CrmCampaign::CHANNELS[$c->channel].' or have no '.($c->channel === 'email' ? 'e-mail address.' : 'usable number.') : '');
    }

    /** Form audience → stored audience (money to minor units). */
    public static function audience(array $a): array
    {
        $a['min_spent_minor'] = ($a['min_spent'] ?? '') === '' || ($a['min_spent'] ?? null) === null ? null : FinanceUi::toMinor($a['min_spent']);
        unset($a['min_spent']);

        return Crm::cleanAudience($a);
    }

    public static function toDomain(array $data): array
    {
        $data['audience'] = self::audience((array) ($data['audience'] ?? []));

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('No.')->color('gray')->searchable(),
                TextColumn::make('name')->weight('bold')->searchable()->description(fn (CrmCampaign $c) => mb_strimwidth($c->body, 0, 80, '…')),
                TextColumn::make('channel')->label('By')->formatStateUsing(fn ($state) => CrmCampaign::CHANNELS[$state] ?? $state),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => CrmCampaign::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'sent' => 'success', 'scheduled' => 'info', 'cancelled' => 'gray', default => 'warning'
                    })
                    ->description(fn (CrmCampaign $c) => $c->status === 'scheduled' ? $c->send_at?->format('j M H:i') : null),
                TextColumn::make('sent')->label('Sent to')->alignEnd()->description(fn (CrmCampaign $c) => $c->skipped ? "{$c->skipped} left out" : null),
                TextColumn::make('delivered')->label('Delivered')->alignEnd()
                    ->state(fn (CrmCampaign $c) => $c->status === 'sent' ? Message::where('reference', $c->number)->where('status', 'sent')->count() : null)
                    ->description(fn (CrmCampaign $c) => $c->status === 'sent' && ($f = Message::where('reference', $c->number)->whereIn('status', ['failed', 'not_configured'])->count()) ? "{$f} failed" : null),
                TextColumn::make('sent_at')->label('Sent')->dateTime('j M Y H:i')->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No campaigns yet')
            ->emptyStateDescription('Tell customers about offers, new stock or reminders — only those who agreed to hear from you.')
            ->recordActions([
                EditAction::make(),
                Action::make('send')->label(fn (CrmCampaign $c) => $c->send_at?->isFuture() ? 'Schedule' : 'Send now')->icon(Heroicon::OutlinedPaperAirplane)->color('success')
                    ->visible(fn (CrmCampaign $c) => $c->status === 'draft' && self::canCreate())
                    ->requiresConfirmation()->modalHeading(fn (CrmCampaign $c) => 'Send “'.$c->name.'”?')
                    ->modalDescription(fn (CrmCampaign $c) => 'It goes to '.app(Crm::class)->preview($c)['reachable'].' people by '.CrmCampaign::CHANNELS[$c->channel]
                        .($c->send_at?->isFuture() ? ' on '.$c->send_at->format('j M Y \a\t H:i') : ' now').'. This cannot be called back.')
                    ->action(fn (CrmCampaign $record) => Erp::run(fn ($actor) => app(Crm::class)->send($actor, $record), $record->send_at?->isFuture() ? 'Scheduled' : 'Sending')),
                Action::make('cancel')->label('Cancel')->icon(Heroicon::OutlinedXMark)->color('gray')->requiresConfirmation()
                    ->visible(fn (CrmCampaign $c) => in_array($c->status, ['draft', 'scheduled'], true) && self::canCreate())
                    ->action(fn (CrmCampaign $record) => Erp::run(fn ($actor) => app(Crm::class)->cancel($actor, $record), 'Cancelled')),
                Action::make('copy')->label('Copy')->icon(Heroicon::OutlinedDocumentDuplicate)->color('gray')
                    ->visible(fn () => self::canCreate())
                    ->action(function (CrmCampaign $record, $livewire) {
                        $copy = Erp::run(fn ($actor) => app(Crm::class)->saveCampaign($actor, new CrmCampaign, ['name' => $record->name.' (copy)']
                            + $record->only(['channel', 'subject', 'body', 'whatsapp_template', 'audience'])));
                        $livewire->redirect(self::getUrl('edit', ['record' => $copy]));
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCrmCampaigns::route('/'),
            'create' => CreateCrmCampaign::route('/create'),
            'edit' => EditCrmCampaign::route('/{record}/edit'),
        ];
    }
}
