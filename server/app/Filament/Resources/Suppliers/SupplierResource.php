<?php

namespace App\Filament\Resources\Suppliers;

use App\Domain\Payables\SupplierPaymentService;
use App\Domain\Purchasing\SupplierMessages;
use App\Domain\Purchasing\SupplierOnboarding;
use App\Domain\Purchasing\SupplierPortal;
use App\Domain\Purchasing\SupplierScorecard;
use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\Resources\Suppliers\RelationManagers\DocumentsRelationManager;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\GlobalSearch;
use App\Models\Supplier;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use UnitEnum;

class SupplierResource extends Resource
{
    use GlobalSearch;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'code', 'phone', 'email'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return static::searchDetails(['Code' => $record->code, 'Phone' => $record->phone]);
    }

    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('purchasing.view') || Erp::can('suppliers.manage');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('suppliers.manage'); // suppliers serve the whole company
    }

    public static function canEdit(Model $record): bool
    {
        return Erp::canAnywhere('suppliers.manage'); // suppliers serve the whole company
    }

    public static function canDelete(Model $record): bool
    {
        return false; // set inactive instead: orders and receipts refer to suppliers
    }

    public static function toFormData(Supplier $s): array
    {
        return ['products' => $s->products()->get()->map(fn ($p) => [
            'product_uom_id' => $p->product_uom_id, 'supplier_sku' => $p->supplier_sku, 'min_order_qty' => Fields::qty($p->min_order_qty), 'order_multiple' => Fields::qty($p->order_multiple),
            'last_cost' => $p->last_cost_minor === null ? null : Money::format($p->last_cost_minor, $s->currency_code), 'is_preferred' => $p->is_preferred,
        ])->all()];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(3)->schema([
                TextInput::make('code')->placeholder('Given by the system')->disabled()->dehydrated(false),
                TextInput::make('name')->required()->maxLength(255)->caps()->columnSpan(2),
                TextInput::make('contact_name')->label('Contact person')->maxLength(255)->caps(),
                TextInput::make('phone')->tel()->maxLength(30),
                TextInput::make('email')->email()->maxLength(255),
                TextInput::make('tax_no')->label('TIN / VAT number')->maxLength(40),
                TextInput::make('payment_terms_days')->label('Payment terms (days)')->numeric()->minValue(0)->maxValue(365)->default(0),
                TextInput::make('lead_time_days')->label('Delivery lead time (days)')->numeric()->minValue(0)->maxValue(365),
                TextInput::make('return_days_before_expiry')->label('Takes back expiring stock up to (days before expiry)')->numeric()->minValue(0)->maxValue(730)
                    ->helperText('Leave empty if they take nothing back. For example 90: stock can go back until 90 days before it expires (Stock → Expiry & returns shows it).'),
                Textarea::make('address')->columnSpan(2),
                Toggle::make('is_active')->label('Active')->default(true)->inline(false),
                Textarea::make('notes')->columnSpanFull(),
            ]),
            Section::make('How they are paid')->columns(3)
                ->description('A new or changed account or MoMo number is checked by a second person (Verify bank details) before any transfer or MoMo payment goes to it.')
                ->schema([
                    Select::make('supply_kind')->label('They supply')->options(Supplier::SUPPLY_KINDS)->default('goods')->required()->selectablePlaceholder(false)
                        ->helperText('Sets the withholding tax on payments to them.'),
                    TextInput::make('bank_name')->label('Bank')->maxLength(80),
                    TextInput::make('bank_branch')->label('Branch')->maxLength(80),
                    TextInput::make('bank_account_name')->label('Account name')->maxLength(120),
                    TextInput::make('bank_account_no')->label('Account number')->maxLength(40),
                    TextInput::make('momo_number')->label('MoMo merchant / number')->maxLength(20),
                    Placeholder::make('bank_check')->label('Checked')->visible(fn (?Supplier $record) => $record?->hasBankDetails())
                        ->content(fn (?Supplier $record) => $record?->bank_verified_at ? "By {$record->bank_verified_by_name}, {$record->bank_verified_at->format('j M Y')}" : 'Not yet: no transfer or MoMo payment until someone else verifies them.'),
                ]),
            Section::make('Products supplied')->description('The pack they sell (e.g. a box of 100 or a carton of 24), their code, your last cost before tax, the least they accept and the steps they sell in (e.g. 5 boxes at a time; 0 = any). Purchase orders fill in from here.')
                ->schema([
                    Repeater::make('products')->hiddenLabel()->columns(7)->defaultItems(0)->addActionLabel('Add product')->schema([
                        Fields::productUnit(buying: true)->label('Product and their pack')->columnSpan(2),
                        TextInput::make('supplier_sku')->label('Their code')->maxLength(60),
                        TextInput::make('last_cost')->label('Cost per pack')->numeric()->minValue(0),
                        TextInput::make('min_order_qty')->label('Minimum order')->numeric()->minValue(0)->default('0'),
                        TextInput::make('order_multiple')->label('In multiples of')->numeric()->minValue(0)->default('0'),
                        Toggle::make('is_preferred')->label('Preferred')->inline(false),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->description(fn (Supplier $r) => $r->contact_name),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => Supplier::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success', 'blocked' => 'danger', default => 'warning'
                    })
                    ->description(fn (Supplier $r) => $r->status === 'blocked' ? $r->blocked_reason : (($g = app(SupplierOnboarding::class)->gaps($r)) ? implode(', ', $g) : null)),
                TextColumn::make('score')->label('Score')->toggleable()
                    ->state(fn (Supplier $r) => ($c = SupplierScorecard::for($r))['grade'] ? "{$c['grade']} ({$c['score']})" : '—')
                    ->tooltip('From the last 12 months: on time, fill rate, faults sent back, price changes. Open the scorecard for details.'),
                TextColumn::make('phone')->placeholder('—'),
                TextColumn::make('payment_terms_days')->label('Terms')->formatStateUsing(fn ($state) => $state ? "{$state} days" : 'Cash'),
                TextColumn::make('products_count')->counts('products')->label('Products'),
                TextColumn::make('owed')->label('We owe')->alignEnd()->visible(fn () => Erp::canAnywhere('payables.view') && config('erp.node.role') !== 'branch')
                    ->state(fn (Supplier $r) => (int) DB::table('supplier_balances')->where('supplier_id', $r->id)->value('balance_minor'))
                    ->formatStateUsing(fn ($state) => $state ? FinanceUi::money((int) $state) : '—'),
                IconColumn::make('is_active')->boolean()->label('Active'),
            ])
            ->filters([TernaryFilter::make('is_active')->label('Active')->default(true), SelectFilter::make('status')->options(Supplier::STATUSES)])
            ->recordActions([
                EditAction::make(),
                Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                    ->visible(fn (Supplier $r) => $r->status === 'pending' && Erp::canAnywhere('suppliers.approve'))
                    ->modalDescription('Check their TIN and documents first. You cannot approve a supplier you added.')
                    ->schema([TextInput::make('note')->label('Note (optional)')->maxLength(255)])
                    ->action(fn (Supplier $r, array $data) => Erp::run(fn ($actor) => app(SupplierOnboarding::class)->approve($actor, $r, $data['note'] ?? null), 'Supplier approved')),
                Action::make('block')->label('Block')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                    ->visible(fn (Supplier $r) => $r->status !== 'blocked' && Erp::canAnywhere('suppliers.approve'))
                    ->modalDescription('No new orders go to them; buyers see the reason.')
                    ->schema([TextInput::make('reason')->label('Reason')->required()->maxLength(255)->placeholder('e.g. Licence suspended by FDA; repeated short deliveries')])
                    ->action(fn (Supplier $r, array $data) => Erp::run(fn ($actor) => app(SupplierOnboarding::class)->block($actor, $r, $data['reason']), 'Supplier blocked')),
                Action::make('unblock')->label('Unblock')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                    ->visible(fn (Supplier $r) => $r->status === 'blocked' && Erp::canAnywhere('suppliers.approve'))
                    ->schema([TextInput::make('reason')->label('Reason')->required()->maxLength(255)])
                    ->action(fn (Supplier $r, array $data) => Erp::run(fn ($actor) => app(SupplierOnboarding::class)->unblock($actor, $r, $data['reason']), 'Supplier unblocked')),
                Action::make('verifyBank')->label('Verify bank details')->icon(Heroicon::OutlinedShieldCheck)->color('warning')
                    ->visible(fn (Supplier $r) => $r->hasBankDetails() && ! $r->bank_verified_at && Erp::canAnywhere('suppliers.approve'))
                    ->modalDescription(fn (Supplier $r) => "Call {$r->name} on a number you already had (not one from the message that changed the details) and confirm: "
                        .collect([$r->bank_name, $r->bank_branch, $r->bank_account_name, $r->bank_account_no, $r->momo_number ? "MoMo {$r->momo_number}" : null])->filter()->implode(' · '))
                    ->schema([TextInput::make('how')->label('How you confirmed them')->required()->maxLength(255)->placeholder('e.g. Called Mr Mensah on 024… (number from our first order)')])
                    ->action(fn (Supplier $r, array $data) => Erp::run(fn ($actor) => app(SupplierOnboarding::class)->verifyBank($actor, $r, $data['how']), 'Bank details verified')),
                Action::make('portal')->label('Portal link')->icon(Heroicon::OutlinedGlobeAlt)->color('gray')
                    ->visible(fn (Supplier $r) => $r->status !== 'blocked' && Erp::canAnywhere('purchasing.manage') && app(SupplierPortal::class)->enabled($r->tenant_id))
                    ->modalHeading(fn (Supplier $r) => "Supplier portal: {$r->name}")
                    ->modalDescription('Their own page: quotes, orders to confirm, goods on the way, invoices, and what is owed and paid. No password: send it only to their own e-mail or WhatsApp.')
                    ->fillForm(fn (Supplier $r) => ['channel' => 'email', 'to' => $r->email ?: $r->phone])
                    ->schema(fn (Supplier $r) => [
                        Radio::make('channel')->label('Send it by')->required()->live()->options(app(SupplierMessages::class)->channels($r->tenant_id) + ['none' => 'Do not send; just show me the link'])
                            ->afterStateUpdated(fn ($state, Set $set) => $set('to', $state === 'email' ? $r->email : $r->phone)),
                        TextInput::make('to')->label(fn (Get $get) => $get('channel') === 'email' ? 'E-mail address' : 'WhatsApp number')->maxLength(160)
                            ->required(fn (Get $get) => $get('channel') !== 'none')->visible(fn (Get $get) => $get('channel') !== 'none'),
                        Checkbox::make('renew')->label('Make a new link (the old one stops working)')->visible((bool) $r->portal_token),
                    ])
                    ->action(function (Supplier $r, array $data) {
                        $portal = app(SupplierPortal::class);
                        $link = Erp::run(fn ($actor) => $portal->link($actor, $r, (bool) ($data['renew'] ?? false)));
                        $open = $data['channel'] === 'none' ? null : Erp::run(fn ($actor) => $portal->send($actor, $r->fresh(), $data['channel'], $data['to']));
                        $n = Notification::make()->success()->persistent();
                        $open
                            ? $n->title('Ready in WhatsApp')->body('Open WhatsApp and press send.')->actions([Action::make('open')->label('Open WhatsApp')->button()->url($open, shouldOpenInNewTab: true)])->send()
                            : $n->title($data['channel'] === 'none' ? 'Their link' : 'Sent')->body($link)->send();
                    }),
                Action::make('scorecard')->label('Scorecard')->icon(Heroicon::OutlinedChartBar)->color('gray')
                    ->modalHeading(fn (Supplier $r) => "Scorecard: {$r->name} (last 12 months)")->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (Supplier $r) => new HtmlString(self::scorecardHtml(SupplierScorecard::for($r)))),
                Action::make('opening')->label('Opening balance')->icon(Heroicon::OutlinedArrowDownOnSquare)->color('gray')
                    ->visible(fn (Supplier $r) => Erp::canAnywhere('finance.journal') && config('erp.node.role') !== 'branch' && ! DB::table('supplier_ledger')->where('supplier_id', $r->id)->exists())
                    ->modalDescription('What you owed this supplier when you started using this system (from your old books). Once only.')
                    ->schema([TextInput::make('amount')->required()->numeric()->minValue(0.01), DatePicker::make('as_of')->label('As at')->required()->default(now()),
                        DatePicker::make('due_on')->label('Due'), TextInput::make('reference')->label('Their invoice number(s)')->maxLength(60)])
                    ->action(fn (Supplier $r, array $data) => Erp::run(fn ($actor) => app(SupplierPaymentService::class)->opening($actor, $r, FinanceUi::toMinor($data['amount']),
                        $data['as_of'], $data['due_on'] ?? null, $data['reference'] ?? null), 'Opening balance recorded')),
            ])
            ->defaultSort('name');
    }

    /** @param array<string, mixed> $c */
    private static function scorecardHtml(array $c): string
    {
        if (! $c['orders']) {
            return '<p>No approved orders in the last 12 months yet.</p>';
        }
        $row = fn (string $label, ?string $value, string $why) => '<tr><td style="padding:6px 10px"><strong>'.e($label).'</strong><br><span style="opacity:.7;font-size:.85em">'.e($why)
            .'</span></td><td style="padding:6px 10px;text-align:right;font-size:1.1em">'.e($value ?? '—').'</td></tr>';

        return '<p style="font-size:1.4em;margin:0 0 8px"><strong>'.e($c['grade'] ?? '—').'</strong> '.($c['score'] !== null ? e((string) $c['score']).' / 100' : '').'</p>'
            .'<table style="width:100%">'
            .$row('On time', $c['on_time'] !== null ? "{$c['on_time']} %" : null, 'Orders whose first delivery came by the expected date (40 % of the score).')
            .$row('Fill rate', $c['fill'] !== null ? "{$c['fill']} %" : null, 'Of what was ordered on finished orders, how much came (30 %).')
            .$row('Sent back for faults', $c['quality_issues'] !== null ? "{$c['quality_issues']} %" : null, 'Damaged, recalled or wrong goods returned, of what was received; expiry returns not counted (20 %).')
            .$row('Price changes on bills', $c['price_change'] !== null ? "{$c['price_change']} %" : null, 'Bills above (+) or below (−) the order price (10 %).')
            .$row('Lead time', $c['lead_days'] !== null ? "{$c['lead_days']} days" : null, 'From approving an order to its first delivery.')
            .$row('Orders', (string) $c['orders'], 'Approved in the last 12 months.')
            .'</table>';
    }

    public static function getRelations(): array
    {
        return [DocumentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/create'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }
}
