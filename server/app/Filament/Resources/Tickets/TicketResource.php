<?php

namespace App\Filament\Resources\Tickets;

use App\Domain\Service\Maintenance;
use App\Domain\Service\ServiceDesk;
use App\Filament\Resources\MaintenanceOrders\MaintenanceOrderResource;
use App\Filament\Resources\Tickets\Pages\CreateTicket;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Support\Erp;
use App\Filament\Support\ServiceUi;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\MaintenanceAsset;
use App\Models\Ticket;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Service desk → Tickets: requests and complaints with their deadlines, from customers and staff. */
class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = 'Service desk';

    protected static ?string $navigationLabel = 'Tickets';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'subject';

    public static function canViewAny(): bool
    {
        return ServiceUi::can('helpdesk.view') || ServiceUi::can('helpdesk.raise');
    }

    public static function canCreate(): bool
    {
        return ServiceUi::can('helpdesk.work') || ServiceUi::can('helpdesk.raise');
    }

    public static function canEdit(Model $record): bool
    {
        return false; // worked from its page (reply, note, pass on, resolve)
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationLabel(): string
    {
        return ServiceUi::can('helpdesk.view') ? 'Tickets' : 'My requests';
    }

    public static function getNavigationBadge(): ?string
    {
        if (! ServiceUi::can('helpdesk.work')) {
            return null;
        }
        $n = Ticket::where('assigned_to', Erp::user()?->id)->whereIn('status', ['new', 'open'])->count();

        return $n ? (string) $n : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return ServiceUi::tickets(parent::getEloquentQuery()->with(['customer', 'assignee']));
    }

    public static function form(Schema $schema): Schema
    {
        $staffOnly = fn () => ! ServiceUi::can('helpdesk.work'); // may only ask for themselves

        return $schema->columns(1)->components([
            Section::make()->columns(3)->schema([
                Select::make('kind')->label('What kind')->options(fn () => $staffOnly() ? ['internal' => Ticket::KINDS['internal']] : Ticket::KINDS)
                    ->default(fn () => $staffOnly() ? 'internal' : 'support')->required()->live()->selectablePlaceholder(false),
                Select::make('priority')->options(Ticket::PRIORITIES)->default('normal')->required()->selectablePlaceholder(false),
                Select::make('source')->label('Came by')->options(['counter' => 'At the counter', 'phone' => 'Phone call', 'whatsapp' => 'WhatsApp', 'email' => 'E-mail'])->default('counter')
                    ->visible(fn (Get $get) => $get('kind') !== 'internal'),
                TextInput::make('subject')->label('In a few words')->required()->maxLength(255)->columnSpanFull(),
                Textarea::make('description')->label('What happened')->rows(4)->columnSpanFull(),
            ]),
            Section::make('Who it is for')->columns(3)->visible(fn (Get $get) => $get('kind') !== 'internal')->schema([
                Select::make('customer_id')->label('Customer')->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Customer::where('is_active', true)->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('phone', 'ilike', "%{$search}%"))->limit(30)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Customer::find($value)?->name)->helperText('Or their name and number below.'),
                TextInput::make('contact_name')->label('Name')->maxLength(120),
                TextInput::make('contact_phone')->label('Phone')->tel()->maxLength(30),
                TextInput::make('contact_email')->label('E-mail')->email()->maxLength(255),
                TextInput::make('receipt_no')->label('Receipt number')->maxLength(40)->helperText('For a return or warranty: the warranty is checked on the sale.'),
            ]),
            Section::make('Where and who')->columns(3)->schema([
                Select::make('asset_id')->label('Equipment it is about')->searchable()->placeholder('—')
                    ->options(fn () => MaintenanceAsset::where('status', '<>', 'retired')->orderBy('number')->get()->mapWithKeys(fn ($a) => [$a->id => "{$a->number} {$a->name}"])),
                Select::make('branch_id')->label('Branch')->placeholder('—')->options(fn () => Branch::where('is_active', true)->orderBy('code')->pluck('name', 'id')),
                Select::make('assigned_to')->label('Give it to')->placeholder('—')->options(fn () => ServiceUi::people('helpdesk.work'))->visible(fn () => ! $staffOnly()),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('No.')->searchable()->color('gray'),
                TextColumn::make('subject')->searchable(['subject', 'contact_name', 'contact_phone', 'contact_email'])->weight('bold')->wrap()
                    ->description(fn (Ticket $t) => trim(($t->customer?->name ?? $t->contact_name ?? '').' · '.Ticket::SOURCES[$t->source], ' ·')),
                TextColumn::make('kind')->label('Kind')->formatStateUsing(fn ($state) => Ticket::KINDS[$state] ?? $state)->toggleable(),
                TextColumn::make('priority')->badge()->formatStateUsing(fn ($state) => Ticket::PRIORITIES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'urgent' => 'danger', 'high' => 'warning', 'low' => 'gray', default => 'info'
                    }),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => Ticket::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'new' => 'warning', 'open' => 'info', 'resolved', 'closed' => 'success', default => 'gray'
                    }),
                TextColumn::make('due')->label('Due')->state(fn (Ticket $t) => $t->deadline()?->diffForHumans() ?? '—')
                    ->color(fn (Ticket $t) => $t->late() ? 'danger' : null)->description(fn (Ticket $t) => $t->deadline() ? ($t->first_response_at ? 'to fix' : 'to answer') : null),
                TextColumn::make('assignee.name')->label('With')->placeholder('Nobody yet'),
                TextColumn::make('created_at')->label('Opened')->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Ticket $t) => self::getUrl('view', ['record' => $t]))
            ->filters([
                TernaryFilter::make('open')->label('Open only')->default(true)
                    ->queries(true: fn ($q) => $q->whereIn('status', ['new', 'open', 'waiting']), false: fn ($q) => $q->whereIn('status', ['resolved', 'closed']), blank: fn ($q) => $q),
                TernaryFilter::make('mine')->label('Given to me')->queries(true: fn ($q) => $q->where('assigned_to', Erp::user()?->id), false: fn ($q) => $q, blank: fn ($q) => $q),
                TernaryFilter::make('late')->label('Late')->queries(true: fn ($q) => $q->whereIn('status', ['new', 'open'])
                    ->where(fn ($w) => $w->where(fn ($a) => $a->whereNull('first_response_at')->where('respond_by', '<', now()))->orWhere('resolve_by', '<', now())), false: fn ($q) => $q, blank: fn ($q) => $q),
                SelectFilter::make('kind')->options(Ticket::KINDS),
                SelectFilter::make('priority')->options(Ticket::PRIORITIES),
            ])
            ->emptyStateHeading('No tickets')
            ->emptyStateDescription('Tickets come from WhatsApp, e-mail and the web form by themselves; open one here for a customer at the counter or on the phone.');
    }

    /** The buttons on a ticket's page. */
    public static function ticketActions(): array
    {
        $desk = app(ServiceDesk::class);
        $work = fn (Ticket $t) => ServiceUi::can('helpdesk.work');
        $channels = fn (Ticket $t) => array_filter(['whatsapp' => $t->contact_phone ? 'WhatsApp' : null, 'sms' => $t->contact_phone ? 'SMS' : null, 'email' => $t->contact_email ? 'E-mail' : null]);

        return [
            Action::make('reply')->label('Reply')->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->visible(fn (Ticket $t) => $t->isOpen() && $t->kind !== 'internal' && $work($t) && $channels($t))
                ->schema(fn (Ticket $t) => [
                    Radio::make('channel')->label('By')->options($channels($t))->default(array_key_first($channels($t)))->inline()->required(),
                    Textarea::make('body')->label('Message')->rows(5)->required(),
                    Checkbox::make('wait')->label('Wait for their answer (the clock stops until they reply)'),
                ])
                ->modalDescription('WhatsApp: a free message reaches them only within 24 hours of their last message; otherwise use SMS or e-mail.')
                ->action(fn (Ticket $record, array $data) => Erp::run(fn ($actor) => $desk->reply($actor, $record, $data['body'], $data['channel'], (bool) ($data['wait'] ?? false)), 'Sent')),
            Action::make('note')->label('Note')->icon(Heroicon::OutlinedPencilSquare)->color('gray')
                ->visible(fn (Ticket $t) => $work($t) || ($t->kind === 'internal' && $t->raised_by === Erp::user()?->id))
                ->schema([Textarea::make('body')->label('Note (staff only)')->rows(4)->required()])
                ->action(fn (Ticket $record, array $data) => Erp::run(fn ($actor) => $desk->note($actor, $record, $data['body']), 'Noted')),
            Action::make('assign')->label('Pass on')->icon(Heroicon::OutlinedUserPlus)->color('gray')
                ->visible(fn (Ticket $t) => $t->isOpen() && $work($t))
                ->fillForm(fn (Ticket $t) => ['assigned_to' => $t->assigned_to ?? Erp::user()?->id, 'priority' => $t->priority])
                ->schema([Select::make('assigned_to')->label('Give it to')->options(fn () => ServiceUi::people('helpdesk.work'))->required(),
                    Select::make('priority')->options(Ticket::PRIORITIES)->required()->selectablePlaceholder(false)->helperText('Deadlines follow the priority, from when it was opened.')])
                ->action(fn (Ticket $record, array $data) => Erp::run(fn ($actor) => $desk->assign($actor, $record, $data['assigned_to'], $data['priority']), 'Passed on')),
            Action::make('workOrder')->label('Work order')->icon(Heroicon::OutlinedWrenchScrewdriver)->color('gray')
                ->visible(fn (Ticket $t) => $t->isOpen() && ServiceUi::can('maintenance.work'))
                ->schema([Select::make('asset_id')->label('Equipment')->required()->searchable()->default(fn (Ticket $t) => $t->asset_id)
                    ->options(fn () => MaintenanceAsset::where('status', '<>', 'retired')->orderBy('number')->get()->mapWithKeys(fn ($a) => [$a->id => "{$a->number} {$a->name}"]))])
                ->action(function (Ticket $record, array $data, $livewire) {
                    $o = Erp::run(fn ($actor) => app(Maintenance::class)->fromTicket($actor, $record, $data['asset_id']), 'Work order opened');
                    $livewire->redirect(MaintenanceOrderResource::getUrl('view', ['record' => $o]));
                }),
            Action::make('resolve')->label('Resolve')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                ->visible(fn (Ticket $t) => $t->isOpen() && $work($t))
                ->schema(fn (Ticket $t) => [
                    Textarea::make('resolution')->label('How it was resolved')->rows(3)->required(),
                    Select::make('tell')->label('Tell them (with “How did we do?”)')->options($channels($t))->placeholder('Do not send')->visible($t->kind !== 'internal' && $channels($t)),
                ])
                ->action(fn (Ticket $record, array $data) => Erp::run(fn ($actor) => $desk->resolve($actor, $record, $data['resolution'], $data['tell'] ?? null), 'Resolved')),
            Action::make('reopen')->label('Reopen')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')->requiresConfirmation()
                ->visible(fn (Ticket $t) => ! $t->isOpen() && ($work($t) || $t->raised_by === Erp::user()?->id))
                ->action(fn (Ticket $record) => Erp::run(fn ($actor) => $desk->reopen($actor, $record), 'Reopened')),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTickets::route('/'),
            'create' => CreateTicket::route('/create'),
            'view' => ViewTicket::route('/{record}'),
        ];
    }
}
