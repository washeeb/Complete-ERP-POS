<?php

namespace App\Filament\Resources\Rfqs;

use App\Domain\Purchasing\OrderSender;
use App\Domain\Purchasing\RfqService;
use App\Domain\Purchasing\SupplierMessages;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Resources\Rfqs\Pages\CreateRfq;
use App\Filament\Resources\Rfqs\Pages\ListRfqs;
use App\Filament\Resources\Rfqs\Pages\ViewRfq;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\MiniTable;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use App\Models\RfqSupplier;
use App\Models\Supplier;
use App\Support\Qty;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Requests for quotation: one list to several suppliers, sealed prices, compared side by side and awarded into draft
 * purchase orders (procurement stage 3).
 */
class RfqResource extends Resource
{
    protected static ?string $model = Rfq::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?string $navigationLabel = 'Requests for quotation';

    protected static ?string $modelLabel = 'request for quotation';

    protected static ?string $pluralModelLabel = 'requests for quotation';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('purchasing.manage') || Erp::canAnywhere('purchasing.approve');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('purchasing.manage');
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
        $ids = [];
        foreach (['purchasing.manage', 'purchasing.approve'] as $p) {
            $b = Erp::branchIds($p);
            if ($b === null) {
                $ids = null;
                break;
            }
            $ids = array_merge($ids, $b);
        }

        return parent::getEloquentQuery()->with('warehouse')->withCount(['lines', 'invitations', 'invitations as quoted_count' => fn ($q) => $q->where('status', 'quoted')])
            ->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    /** Suppliers that may be asked: active, and approved when supplier controls are on (the service checks again). */
    public static function supplierOptions(): array
    {
        return Supplier::where('is_active', true)->where('status', '!=', 'blocked')->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(3)->schema([
                TextInput::make('title')->label('Title')->required()->maxLength(160)->placeholder('e.g. Antimalarials for November')->columnSpan(2),
                Select::make('warehouse_id')->label('Deliver to')->required()->live()->options(fn () => Fields::warehouses('purchasing.manage')),
                DateTimePicker::make('closes_at')->label('Quotes close')->required()->seconds(false)->default(now()->addDays(3)->setTime(17, 0))->minDate(now())
                    ->helperText('Prices stay sealed until then, unless you close the request earlier.'),
                DatePicker::make('needed_by')->label('Needed by (optional)')->minDate(today()),
                Select::make('supplier_ids')->label('Suppliers to ask')->multiple()->required()->searchable()->options(fn () => self::supplierOptions())
                    ->helperText('Three or more give a fair comparison.'),
                Textarea::make('terms')->label('What the suppliers are told (optional)')->rows(3)->maxLength(2000)->columnSpanFull()
                    ->placeholder('e.g. Delivery to our Tarkwa store. Prices before VAT. At least 12 months to expiry. State your credit terms.'),
            ]),
            Section::make('Items')->schema([
                Repeater::make('lines')->hiddenLabel()->itemNumbers()->columns(7)->minItems(1)->addActionLabel('Add line')->schema([
                    Fields::productUnit(buying: true)->live()->columnSpan(3),
                    TextInput::make('qty')->label('Quantity')->required()->numeric()->minValue(0.001),
                    Fields::available('../../warehouse_id')->label('In stock now'),
                    TextInput::make('note')->label('Note to suppliers (optional)')->maxLength(255)->columnSpan(2),
                    Fields::duplicateWarning(),
                ]),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('Request'),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state, Rfq $record) => $state === 'open' && ! $record->acceptsQuotes() ? 'Closing time passed: compare and award' : (Rfq::STATUSES[$state] ?? $state)),
                TextEntry::make('title')->columnSpan(2),
                TextEntry::make('warehouse.name')->label('Deliver to'),
                TextEntry::make('closes_at')->label('Quotes close')->dateTime(),
                TextEntry::make('needed_by')->label('Needed by')->date()->placeholder('—'),
                TextEntry::make('created_by_name')->label('Made by'),
                TextEntry::make('purchaseRequest.number')->label('From purchase request')->placeholder('—')
                    ->url(fn (Rfq $record) => $record->purchase_request_id ? PurchaseRequestResource::getUrl('view', ['record' => $record->purchase_request_id]) : null),
                TextEntry::make('awarded_by_name')->label('Awarded by')->placeholder('—')
                    ->formatStateUsing(fn ($state, Rfq $record) => $state.($record->awarded_at ? ', '.$record->awarded_at->format('j M Y H:i') : '')),
                TextEntry::make('award_note')->label('Why (award)')->placeholder('—')->columnSpan(2),
                TextEntry::make('cancelled_reason')->label('Cancelled because')->visible(fn (Rfq $record) => $record->status === 'cancelled'),
                TextEntry::make('terms')->label('Told to suppliers')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Items')->schema([
                TextEntry::make('items')->hiddenLabel()->state(fn (Rfq $record) => MiniTable::html(['#', 'Item', 'Quantity', 'Note'],
                    $record->lines->map(fn ($l) => [$l->line_no, "{$l->sku} · {$l->name}", Fields::qty((string) $l->qty, $l->uom_code), $l->note ?? ''])->all(), [2])),
            ]),
            Section::make('Suppliers asked')->schema([
                TextEntry::make('suppliers')->hiddenLabel()->state(fn (Rfq $record) => self::invitationsTable($record)),
            ]),
            Section::make('Comparison')->description('Score out of 100: price 50, delivery time 20, credit terms 10, past performance 20 (scorecard; a new supplier gets half). ★ = cheapest for the line.')
                ->visible(fn (Rfq $record) => $record->isUnsealed() && $record->invitations()->where('status', 'quoted')->exists())
                ->schema([TextEntry::make('comparison')->hiddenLabel()->state(fn (Rfq $record) => self::comparisonTable($record))]),
        ]);
    }

    private static function invitationsTable(Rfq $record): HtmlString
    {
        $unsealed = $record->isUnsealed();
        $rows = $record->invitations()->with('supplier')->get()->map(function (RfqSupplier $i) use ($unsealed) {
            $answer = match ($i->status) {
                'quoted' => 'Quoted '.$i->quoted_at?->format('j M H:i').($i->quoted_by_name ? " ({$i->quoted_by_name})" : ''),
                'declined' => 'Declined'.($i->note ? ": {$i->note}" : ''),
                default => '—',
            };
            $row = [$i->supplier?->name, $i->sent_at ? $i->sent_at->format('j M H:i').' · '.(OrderSender::CHANNELS[$i->sent_via] ?? $i->sent_via) : 'Not sent',
                $i->viewed_at?->format('j M H:i') ?? '—', $answer];
            if ($unsealed) {
                $row[] = $i->status === 'quoted' ? FinanceUi::money($i->total_minor) : '';
                $row[] = $i->purchase_order_id ? PurchaseOrder::whereKey($i->purchase_order_id)->value('number') : '';
            }

            return $row;
        })->all();
        $head = ['Supplier', 'Sent', 'Seen', 'Answer'];

        return MiniTable::html($unsealed ? [...$head, 'Total before VAT', 'Order'] : $head, $rows, $unsealed ? [4] : [], 'No suppliers asked.');
    }

    private static function comparisonTable(Rfq $record): HtmlString
    {
        $cmp = app(RfqService::class)->compare($record);
        $ids = array_keys($cmp['offers']);
        $head = ['Item', ...array_map(fn ($id) => $cmp['offers'][$id]['name'], $ids)];
        $rows = [];
        foreach ($cmp['lines'] as $l) {
            $row = ["{$l['line']->name} (".Fields::qty((string) $l['line']->qty, $l['line']->uom_code).')'];
            foreach ($ids as $id) {
                $q = $l['quotes'][$id] ?? null;
                $row[] = $q ? FinanceUi::money($q->unit_cost_minor).($l['cheapest'] === $id ? ' ★' : '')
                    .(Qty::toMilli((string) $q->qty) < Qty::toMilli((string) $l['line']->qty) ? ' (only '.Fields::qty((string) $q->qty).')' : '').($q->awarded ? ' ✔' : '') : '—';
            }
            $rows[] = $row;
        }
        $o = fn (string $label, callable $f) => [$label, ...array_map(fn ($id) => $f($cmp['offers'][$id]), $ids)];
        $rows[] = $o('Total before VAT', fn ($x) => FinanceUi::money($x['invitation']->total_minor));
        $rows[] = $o('Delivery (days)', fn ($x) => $x['invitation']->delivery_days ?? '—');
        $rows[] = $o('Credit (days)', fn ($x) => $x['invitation']->payment_days ?? '—');
        $rows[] = $o('Prices hold until', fn ($x) => ($x['invitation']->valid_until?->format('j M Y') ?? '—').($x['expired'] ? ' (expired)' : ''));
        $rows[] = $o('Past performance', fn ($x) => $x['record'] === null ? 'New' : "{$x['record']} ({$x['grade']})");
        $rows[] = $o('Their note', fn ($x) => $x['invitation']->note ?? '');
        $rows[] = $o('Score', fn ($x) => $x['score'].' = '.implode(' + ', array_map(fn ($p) => (string) round($p), $x['parts'])).($cmp['best'] === $x['invitation']->id ? ' · best' : ''));

        return MiniTable::html($head, $rows, range(1, count($ids)));
    }

    /** The header buttons of a request for quotation. */
    public static function workflowActions(): array
    {
        $svc = fn () => app(RfqService::class);
        $open = fn (Rfq $record) => $record->acceptsQuotes() && Erp::can('purchasing.manage', $record->warehouse);
        $decidable = fn (Rfq $record) => in_array($record->status, ['open', 'closed'], true) && $record->isUnsealed() && Erp::can('purchasing.manage', $record->warehouse);
        $invitations = fn (Rfq $record, bool $all = true) => $record->invitations()->with('supplier')->when(! $all, fn ($q) => $q->where('status', '!=', 'declined'))->get()
            ->mapWithKeys(fn (RfqSupplier $i) => [$i->id => $i->supplier->name.' · '.RfqSupplier::STATUSES[$i->status].($i->sent_at ? '' : ' · not sent')])->all();

        return [
            Action::make('send')->label('Send to a supplier')->icon(Heroicon::OutlinedPaperAirplane)->color('primary')->visible($open)
                ->modalDescription('The supplier gets a link to their own page for this request, where they enter prices. Send each supplier their own link.')
                ->schema(fn (Rfq $record) => [
                    Select::make('invitation')->label('Supplier')->required()->live()->options($invitations($record, false))
                        ->afterStateUpdated(function ($state, Set $set, Get $get) {
                            $s = RfqSupplier::find($state)?->supplier;
                            $set('to', $get('channel') === 'email' ? $s?->email : $s?->phone);
                        }),
                    Radio::make('channel')->label('How')->required()->live()->default('email')->options(app(SupplierMessages::class)->channels($record->tenant_id))
                        ->afterStateUpdated(function ($state, Set $set, Get $get) {
                            $s = RfqSupplier::find($get('invitation'))?->supplier;
                            $set('to', $state === 'email' ? $s?->email : $s?->phone);
                        }),
                    TextInput::make('to')->label(fn (Get $get) => $get('channel') === 'email' ? 'E-mail address' : 'WhatsApp number')->required()->maxLength(160),
                    Textarea::make('note')->label('Message (optional)')->rows(2)->maxLength(500),
                ])
                ->action(function (array $data) use ($svc) {
                    $openUrl = Erp::run(fn ($actor) => $svc()->send($actor, RfqSupplier::findOrFail($data['invitation']), $data['channel'], $data['to'], $data['note'] ?? null));
                    $openUrl
                        ? Notification::make()->success()->title('Ready in WhatsApp')->body('Open WhatsApp and press send.')->persistent()
                            ->actions([Action::make('open')->label('Open WhatsApp')->button()->url($openUrl, shouldOpenInNewTab: true)])->send()
                        : Notification::make()->success()->title('Sent')->body('See Messages → Message log if it does not arrive.')->send();
                }),
            Action::make('links')->label('Supplier links')->icon(Heroicon::OutlinedLink)->color('gray')->visible($open)
                ->modalHeading('Each supplier\'s own link')->modalDescription('To send another way (SMS, your own e-mail). Each link shows only that supplier\'s page; do not send one supplier another\'s link.')
                ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn (Rfq $record) => MiniTable::html(['Supplier', 'Link'], $record->invitations()->with('supplier')->get()
                    ->map(fn (RfqSupplier $i) => [$i->supplier->name, $i->link()])->all())),
            Action::make('invite')->label('Ask another supplier')->icon(Heroicon::OutlinedUserPlus)->color('gray')->visible($open)
                ->schema([Select::make('supplier_id')->label('Supplier')->required()->searchable()->options(fn () => self::supplierOptions())])
                ->action(fn (Rfq $record, array $data) => Erp::run(fn ($actor) => $svc()->invite($actor, $record, Supplier::findOrFail($data['supplier_id'])), 'Added: now send them the request')),
            Action::make('enterQuote')->label('Enter a quote')->icon(Heroicon::OutlinedPencilSquare)->color('gray')->visible($open)
                ->modalDescription('For a quote that came by paper, phone or e-mail. Attach nothing here; keep the paper. It replaces any earlier quote from that supplier.')
                ->modalWidth('4xl')
                ->fillForm(fn (Rfq $record) => ['lines' => $record->lines->map(fn ($l) => ['rfq_line_id' => $l->id, 'item' => "{$l->name} (".Fields::qty((string) $l->qty, $l->uom_code).')',
                    'qty' => Fields::qty((string) $l->qty)])->all()])
                ->schema(fn (Rfq $record) => [
                    Select::make('invitation')->label('Supplier')->required()->options($invitations($record)),
                    TextInput::make('from')->label('Their person who gave the quote (optional)')->maxLength(100),
                    Repeater::make('lines')->addable(false)->deletable(false)->reorderable(false)->columns(4)->schema([
                        TextInput::make('item')->disabled()->dehydrated(false)->columnSpan(2),
                        TextInput::make('price')->label('Unit price before VAT')->numeric()->minValue(0.0001)->helperText('Empty = not offered'),
                        TextInput::make('qty')->label('They can supply')->numeric()->minValue(0.001),
                        Hidden::make('rfq_line_id'),
                    ]),
                    TextInput::make('delivery_days')->label('Delivery (days)')->numeric()->minValue(0)->maxValue(365),
                    TextInput::make('payment_days')->label('Credit (days)')->numeric()->minValue(0)->maxValue(365),
                    DatePicker::make('valid_until')->label('Prices hold until'),
                    TextInput::make('note')->label('Note')->maxLength(500),
                ])
                ->action(function (array $data) use ($svc) {
                    $lines = collect($data['lines'])->mapWithKeys(fn ($l) => [$l['rfq_line_id'] => ['price' => (string) ($l['price'] ?? ''), 'qty' => (string) ($l['qty'] ?? '')]])->all();
                    Erp::run(fn ($actor) => $svc()->quote(RfqSupplier::findOrFail($data['invitation']), ['lines' => $lines] + $data, trim((string) ($data['from'] ?? '')) ?: $actor->name, $actor), 'Quote entered');
                }),
            Action::make('close')->label('Close and compare')->icon(Heroicon::OutlinedLockOpen)->color('warning')
                ->visible(fn (Rfq $record) => $record->status === 'open' && Erp::can('purchasing.manage', $record->warehouse))
                ->requiresConfirmation()->modalDescription(fn (Rfq $record) => $record->closes_at->isFuture()
                    ? 'Suppliers can no longer send or change prices, and the prices are opened now, before the closing time of '.$record->closes_at->format('j M H:i').'. This is recorded.'
                    : 'Suppliers can no longer send prices.')
                ->action(fn (Rfq $record) => Erp::run(fn ($actor) => $svc()->close($actor, $record), 'Closed: the prices are open')),
            Action::make('award')->label('Award')->icon(Heroicon::OutlinedTrophy)->color('success')
                ->visible(fn (Rfq $record) => $decidable($record) && $record->invitations()->where('status', 'quoted')->exists())
                ->modalWidth('4xl')
                ->modalDescription('Choose who supplies each item. Each supplier chosen gets a draft purchase order at their prices, which goes for approval as usual. Giving an item to anyone but the cheapest needs the reason.')
                ->fillForm(function (Rfq $record) use ($svc) {
                    $cmp = $svc()->compare($record);

                    return ['awards' => collect($cmp['lines'])->map(fn ($l) => ['rfq_line_id' => $l['line']->id, 'item' => "{$l['line']->name} (".Fields::qty((string) $l['line']->qty, $l['line']->uom_code).')',
                        'invitation' => isset($l['quotes'][$cmp['best']]) ? $cmp['best'] : $l['cheapest']])->values()->all()];
                })
                ->schema(function (Rfq $record) use ($svc) {
                    $cmp = $svc()->compare($record);
                    $options = fn (?string $lineId) => collect($cmp['lines'][$lineId]['quotes'] ?? [])->mapWithKeys(fn ($q, $id) => [$id => $cmp['offers'][$id]['name'].' · '.FinanceUi::money($q->unit_cost_minor)
                        .($cmp['lines'][$lineId]['cheapest'] === $id ? ' ★ cheapest' : '').' · score '.$cmp['offers'][$id]['score']])->all();

                    return [
                        Repeater::make('awards')->hiddenLabel()->addable(false)->deletable(false)->reorderable(false)->columns(2)->schema([
                            TextInput::make('item')->disabled()->dehydrated(false),
                            Select::make('invitation')->label('Supplier')->placeholder('Do not buy now')->options(fn (Get $get) => $options($get('rfq_line_id'))),
                            Hidden::make('rfq_line_id'),
                        ]),
                        Textarea::make('note')->label('Why (needed when an item is not going to the cheapest, or prices have expired)')->rows(2)->maxLength(500),
                    ];
                })
                ->action(function (Rfq $record, array $data, Action $action) use ($svc) {
                    $awards = collect($data['awards'])->mapWithKeys(fn ($a) => [$a['rfq_line_id'] => $a['invitation'] ?? null])->all();
                    $pos = Erp::run(fn ($actor) => $svc()->award($actor, $record, $awards, $data['note'] ?? null));
                    Notification::make()->success()->title('Awarded: '.implode(', ', array_map(fn ($p) => $p->number, $pos)))
                        ->body('Draft purchase order(s) made. Check and submit them for approval.')->send();
                    $action->redirect(count($pos) === 1 ? PurchaseOrderResource::getUrl('edit', ['record' => $pos[0]]) : PurchaseOrderResource::getUrl('index'));
                }),
            Action::make('cancel')->label('Cancel')->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->visible(fn (Rfq $record) => in_array($record->status, ['open', 'closed'], true) && Erp::can('purchasing.manage', $record->warehouse))
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(fn (Rfq $record, array $data) => Erp::run(fn ($actor) => $svc()->cancel($actor, $record, $data['reason']), 'Cancelled')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Request')->searchable()->sortable(),
                TextColumn::make('title')->searchable()->wrap(),
                TextColumn::make('warehouse.name')->label('Deliver to'),
                TextColumn::make('lines_count')->label('Items'),
                TextColumn::make('quoted_count')->label('Quotes')->formatStateUsing(fn ($state, Rfq $record) => "{$state} of {$record->invitations_count}"),
                TextColumn::make('closes_at')->label('Close')->dateTime('j M H:i')->sortable(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state, Rfq $record) => $state === 'open' && ! $record->acceptsQuotes() ? 'Ready to compare' : (Rfq::STATUSES[$state] ?? $state))
                    ->color(fn ($state, Rfq $record) => match (true) {
                        $state === 'open' && $record->acceptsQuotes() => 'info', in_array($state, ['open', 'closed'], true) => 'warning', $state === 'awarded' => 'success', default => 'gray',
                    }),
            ])
            ->filters([SelectFilter::make('status')->options(Rfq::STATUSES)])
            ->recordActions([ViewAction::make()])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No requests for quotation yet')
            ->emptyStateDescription('Ask several suppliers for prices on the same list, compare them side by side and award the best.');
    }

    public static function getPages(): array
    {
        return ['index' => ListRfqs::route('/'), 'create' => CreateRfq::route('/create'), 'view' => ViewRfq::route('/{record}')];
    }
}
