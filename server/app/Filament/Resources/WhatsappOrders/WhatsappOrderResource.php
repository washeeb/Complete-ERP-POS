<?php

namespace App\Filament\Resources\WhatsappOrders;

use App\Domain\Channels\WhatsappOrders;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Filament\Resources\WhatsappOrders\Pages\ListWhatsappOrders;
use App\Filament\Resources\WhatsappOrders\Pages\ViewWhatsappOrder;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\Branch;
use App\Models\WhatsappOrder;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use UnitEnum;

/** Orders and prescription photos customers send by WhatsApp, for staff to turn into orders or decline. */
class WhatsappOrderResource extends Resource
{
    protected static ?string $model = WhatsappOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Online';

    protected static ?string $navigationLabel = 'WhatsApp orders';

    protected static ?string $modelLabel = 'WhatsApp order';

    protected static ?int $navigationSort = 2;

    /** Shown once WhatsApp orders are switched on (or some came in), to people who handle online orders. */
    public static function canViewAny(): bool
    {
        return Erp::canAnywhere(WhatsappOrders::PERMISSION)
            && (app(Settings::class)->get('whatsapp.orders', Erp::user()?->tenant_id) || WhatsappOrder::exists());
    }

    public static function canCreate(): bool
    {
        return false; // they come from WhatsApp
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
        $n = WhatsappOrder::where('status', 'new')->count();

        return $n ? (string) $n : null;
    }

    public static function from(WhatsappOrder $o): string
    {
        return trim(($o->customer?->name ?? $o->from_name ?? '').' +'.$o->from_phone);
    }

    /** Photos shown in the page; documents (PDF) as links. Each opens full size in a new tab. */
    public static function files(WhatsappOrder $o): HtmlString
    {
        $html = collect($o->media ?? [])->map(function ($m, $i) use ($o) {
            if (! ($m['path'] ?? null)) {
                return '<span class="wa-file wa-missing">'.e(($m['tries'] ?? 0) >= WhatsappOrders::MEDIA_TRIES ? 'Not fetched: '.($m['error'] ?? 'unknown') : 'Fetching from WhatsApp…').'</span>';
            }
            $url = e(route('whatsapp.file', ['order' => $o->id, 'index' => $i]));

            return str_starts_with((string) $m['mime'], 'image/')
                ? "<a href=\"{$url}\" target=\"_blank\" rel=\"noopener\"><img src=\"{$url}\" alt=\"Photo from the customer\" style=\"max-height:260px;border-radius:8px;display:inline-block;margin:4px\"></a>"
                : "<a href=\"{$url}\" target=\"_blank\" rel=\"noopener\" class=\"underline\">".e($m['name'] ?? 'Document').'</a>';
        })->implode(' ');

        return new HtmlString($html ?: '—');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('Reference'),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => WhatsappOrder::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'new' => 'warning', 'ordered' => 'success', default => 'gray'
                    }),
                TextEntry::make('from_phone')->label('From')->state(fn (WhatsappOrder $record) => self::from($record)),
                TextEntry::make('received_at')->label('Received')->dateTime(),
                TextEntry::make('customer.name')->label('Customer record')->placeholder('Not a known customer (matched by phone number)'),
                TextEntry::make('salesOrder.number')->label('Order')->placeholder('—')
                    ->url(fn (WhatsappOrder $record) => $record->sales_order_id ? SalesOrderResource::getUrl('view', ['record' => $record->sales_order_id]) : null),
                TextEntry::make('handled_by_name')->label('Handled by')->placeholder('—')
                    ->formatStateUsing(fn ($state, WhatsappOrder $record) => $state.($record->handled_at ? ', '.$record->handled_at->format('j M H:i') : '')),
                TextEntry::make('decline_reason')->label('Declined because')->placeholder('—'),
            ]),
            Section::make('Message')->schema([
                TextEntry::make('body')->hiddenLabel()->placeholder('(no text)')->formatStateUsing(fn ($state) => new HtmlString(nl2br(e((string) $state)))),
                TextEntry::make('media')->label('Photos and documents')->state(fn (WhatsappOrder $record) => self::files($record)),
            ]),
        ]);
    }

    /** @return list<Action> */
    public static function orderActions(): array
    {
        $svc = fn () => app(WhatsappOrders::class);
        $branches = function () {
            $ids = Erp::branchIds(WhatsappOrders::PERMISSION);

            return Branch::where('is_active', true)->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name')->pluck('name', 'id')->all();
        };

        return [
            Action::make('makeOrder')->label('Make the order')->icon(Heroicon::OutlinedShoppingBag)->color('success')->modalWidth('3xl')
                ->visible(fn (WhatsappOrder $record) => $record->status === 'new')
                ->modalDescription(fn (WhatsappOrder $record) => 'Stock is held for it, and the customer is sent the items and the total on WhatsApp. Their message: '
                    .Str::limit((string) $record->body, 300, '…'))
                ->fillForm(fn () => ['branch_id' => count($b = $branches()) === 1 ? array_key_first($b) : null, 'fulfilment' => 'pickup', 'lines' => [['qty' => 1]]])
                ->schema([
                    Select::make('branch_id')->label('Branch that fills it')->options($branches)->required(),
                    Radio::make('fulfilment')->label('The customer')->options(['pickup' => 'Picks it up', 'delivery' => 'Gets it delivered'])->inline()->live()->required(),
                    Textarea::make('address')->label('Deliver to')->rows(2)->visible(fn (Get $get) => $get('fulfilment') === 'delivery')->required(fn (Get $get) => $get('fulfilment') === 'delivery'),
                    Repeater::make('lines')->label('Items')->minItems(1)->columns(4)->addActionLabel('Add an item')->schema([
                        Fields::productUnit()->columnSpan(3),
                        TextInput::make('qty')->label('Quantity')->numeric()->minValue(0.001)->required()->default(1),
                    ]),
                    TextInput::make('note')->label('Note on the order (optional)')->maxLength(255),
                ])
                ->action(function (WhatsappOrder $record, array $data, $livewire) use ($svc) {
                    $order = Erp::run(fn ($actor) => $svc()->makeOrder($actor, $record, $data), 'Order made; the customer was sent the total');
                    if ($order) {
                        $livewire->redirect(SalesOrderResource::getUrl('view', ['record' => $order->id]));
                    }
                }),
            Action::make('reply')->label('Reply')->icon(Heroicon::OutlinedChatBubbleLeft)->color('gray')
                ->modalDescription('A WhatsApp message to the customer, e.g. to ask which strength or to give a price. WhatsApp allows replies within 24 hours of their last message.')
                ->schema([Textarea::make('text')->label('Message')->rows(3)->required()->maxLength(1000)])
                ->action(fn (WhatsappOrder $record, array $data) => Erp::run(fn ($actor) => $svc()->reply($actor, $record, $data['text']) ?? true, 'Sent')),
            Action::make('decline')->label('Decline')->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->visible(fn (WhatsappOrder $record) => $record->status === 'new')
                ->modalDescription('The customer is told on WhatsApp, with your reason.')
                ->schema([TextInput::make('reason')->label('Why (the customer sees this)')->required()->maxLength(255)->placeholder('e.g. out of stock, or a prescription is needed')])
                ->action(fn (WhatsappOrder $record, array $data) => Erp::run(fn ($actor) => $svc()->decline($actor, $record, $data['reason']), 'Declined; the customer was told')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('customer'))
            ->columns([
                TextColumn::make('number')->label('Reference')->searchable(),
                TextColumn::make('last_message_at')->label('Received')->since()->sortable(),
                TextColumn::make('from_phone')->label('From')->searchable(['from_phone', 'from_name'])->state(fn (WhatsappOrder $record) => self::from($record)),
                TextColumn::make('body')->label('Message')->limit(80)->placeholder('(photo only)')->wrap(),
                TextColumn::make('media')->label('Photos')->state(fn (WhatsappOrder $record) => count($record->media ?? []) ?: null)->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => WhatsappOrder::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'new' => 'warning', 'ordered' => 'success', default => 'gray'
                    }),
            ])
            ->filters([SelectFilter::make('status')->options(WhatsappOrder::STATUSES)->multiple()->default(['new'])])
            ->defaultSort('last_message_at', 'desc')
            ->recordActions([ViewAction::make()->label('Open')])
            ->poll('30s')
            ->emptyStateHeading('No WhatsApp orders waiting')
            ->emptyStateDescription('Customers\' WhatsApp messages to your business number appear here.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWhatsappOrders::route('/'),
            'view' => ViewWhatsappOrder::route('/{record}'),
        ];
    }
}
