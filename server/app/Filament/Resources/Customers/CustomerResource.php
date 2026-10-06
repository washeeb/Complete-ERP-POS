<?php

namespace App\Filament\Resources\Customers;

use App\Domain\Customers\CustomerLedger;
use App\Domain\Customers\CustomerService;
use App\Domain\Sales\ReceivablesService;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\SalePayment;
use App\Models\Tenant;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 0;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('customers.view');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('customers.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return Erp::canAnywhere('customers.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return false; // set inactive: invoices and sales refer to customers
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('balance', 'priceList');
    }

    public static function currency(): string
    {
        return Tenant::find(Erp::user()->tenant_id)->currency_code;
    }

    public static function money(?int $minor): string
    {
        return $minor === null ? '—' : Money::display($minor, self::currency());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(3)->schema([
                Select::make('type')->options(Customer::TYPES)->default('individual')->required(),
                TextInput::make('name')->required()->maxLength(255)->columnSpan(2),
                TextInput::make('phone')->tel()->maxLength(30)->helperText('Ghana numbers are stored as +233…'),
                TextInput::make('email')->email()->maxLength(255),
                TextInput::make('contact_name')->label('Contact person')->maxLength(255),
                TextInput::make('tax_no')->label('TIN / Ghana Card')->maxLength(40),
                Select::make('price_list_id')->label('Price list')->placeholder('Company default')
                    ->options(fn () => PriceList::where('is_active', true)->orderBy('name')->pluck('name', 'id')),
                Select::make('home_branch_id')->label('Home branch')->options(fn () => Branch::orderBy('code')->pluck('name', 'id')),
                Textarea::make('address')->columnSpan(2),
                TagsInput::make('tags')->placeholder('e.g. wholesale, VIP'),
                Toggle::make('is_active')->label('Active')->default(true)->inline(false),
                Textarea::make('notes')->columnSpanFull(),
            ]),
            Section::make('Marketing consent')->description('Only tick what the customer agreed to. Every change is kept with who recorded it, when and how.')
                ->columns(4)->schema([
                    Toggle::make('consents.sms')->label('SMS'),
                    Toggle::make('consents.whatsapp')->label('WhatsApp'),
                    Toggle::make('consents.email')->label('Email'),
                    Select::make('consent_source')->label('How they agreed')->options(['in person' => 'In person', 'paper form' => 'Paper form', 'phone call' => 'Phone call', 'online' => 'Online'])
                        ->default('in person')->dehydrated(),
                ]),
            Section::make('Credit (head office)')->visibleOn('create')->visible(fn () => Erp::can('customers.credit.manage') && config('erp.node.role') !== 'branch')
                ->columns(2)->schema([
                    TextInput::make('credit_limit')->label('Credit limit')->numeric()->minValue(0)->helperText('Leave blank for a cash customer (no credit).'),
                    TextInput::make('payment_terms_days')->label('Payment terms (days)')->numeric()->minValue(0)->maxValue(365)->default(0),
                ]),
        ]);
    }

    /** Form data → domain data (consents as booleans, money to minor units). */
    public static function toDomain(array $data): array
    {
        $data['consents'] = array_map(fn ($v) => (bool) $v, $data['consents'] ?? []);
        if (array_key_exists('credit_limit', $data)) {
            $data['credit_limit_minor'] = $data['credit_limit'] === null || $data['credit_limit'] === '' ? null : Money::toMinor((string) $data['credit_limit'], self::currency());
        }
        unset($data['credit_limit'], $data['consent_source']);

        return $data;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('code'),
                TextEntry::make('name')->weight('bold'),
                TextEntry::make('phone')->placeholder('—'),
                TextEntry::make('email')->placeholder('—'),
                TextEntry::make('type')->formatStateUsing(fn ($state) => Customer::TYPES[$state] ?? $state),
                TextEntry::make('tax_no')->label('TIN')->placeholder('—'),
                TextEntry::make('priceList.name')->label('Price list')->placeholder('Company default'),
                TextEntry::make('consents')->label('Consent')->state(fn (Customer $record) => collect(Customer::CONSENT_CHANNELS)
                    ->filter(fn ($l, $ch) => $record->hasConsent($ch))->values()->implode(', ') ?: 'none'),
            ]),
            Section::make('Account')->columns(5)->schema([
                TextEntry::make('balance')->label('Balance')->weight('bold')->state(fn (Customer $record) => self::money($record->balanceMinor()))
                    ->color(fn (Customer $record) => $record->balanceMinor() > 0 ? 'warning' : null),
                TextEntry::make('overdue')->label('Overdue')->state(fn (Customer $record) => self::money(app(CustomerLedger::class)->overdueMinor($record->id)))
                    ->color(fn ($state) => $state !== self::money(0) ? 'danger' : null),
                TextEntry::make('credit_limit_minor')->label('Credit limit')->formatStateUsing(fn ($state) => self::money($state))->placeholder('No credit (cash)'),
                TextEntry::make('available')->label('Available credit')->state(fn (Customer $record) => self::money(app(CustomerService::class)->availableCredit($record))),
                TextEntry::make('payment_terms_days')->label('Terms')->formatStateUsing(fn ($state) => $state ? "{$state} days" : 'Cash')
                    ->belowContent(fn (Customer $record) => $record->credit_hold ? 'ON CREDIT HOLD' : null),
            ]),
            Section::make('Open items')->description('Invoices and till credit sales not yet fully paid, oldest first.')->schema([
                TextEntry::make('open_items')->hiddenLabel()->html()->state(function (Customer $record) {
                    $rows = app(CustomerLedger::class)->openItems($record->id);
                    if ($rows === []) {
                        return '<span class="text-gray-500">Nothing outstanding.</span>';
                    }

                    return '<table class="w-full text-sm"><tr class="text-left text-gray-500"><th>Document</th><th>Due</th><th class="text-right">Amount</th><th class="text-right">Still owed</th></tr>'
                        .collect($rows)->map(fn ($r) => '<tr><td>'.e($r->doc_number).'</td><td>'.e($r->due_on ?? '—').($r->due_on && $r->due_on < now()->toDateString() ? ' <b class="text-danger-600">overdue</b>' : '')
                            .'</td><td class="text-right">'.e(self::money((int) $r->amount_minor)).'</td><td class="text-right">'.e(self::money((int) $r->open_minor)).'</td></tr>')->implode('').'</table>';
                }),
            ]),
        ]);
    }

    /** @return list<Action> */
    public static function accountActions(): array
    {
        return [
            Action::make('pay')->label('Record payment')->icon(Heroicon::OutlinedBanknotes)->color('success')
                ->visible(fn () => Erp::canAnywhere('receivables.collect'))
                ->schema([
                    Select::make('branch_id')->label('Received at')->required()->options(fn () => Branch::orderBy('code')->pluck('name', 'id'))
                        ->default(fn () => Branch::where('is_hq', true)->value('id')),
                    TextInput::make('amount')->required()->numeric()->minValue(0.01),
                    Select::make('method')->required()->options(ReceivablesService::METHODS)->live(),
                    Select::make('provider')->label('Network')->options(SalePayment::MOMO_PROVIDERS)->visible(fn (Get $get) => $get('method') === 'momo'),
                    TextInput::make('reference')->label('Transaction / cheque no.')->maxLength(80),
                    DatePicker::make('received_on')->default(now())->maxDate(now()),
                    TextInput::make('note')->maxLength(255),
                ])
                ->action(fn (Customer $record, array $data) => Erp::run(fn ($actor) => app(ReceivablesService::class)->recordPayment($actor, $record,
                    Branch::findOrFail($data['branch_id']), $data['method'], Money::toMinor((string) $data['amount'], self::currency()), $data['reference'] ?? null,
                    $data['received_on'] ?? null, null, $data['provider'] ?? null, $data['note'] ?? null), 'Payment recorded')),
            Action::make('statement')->label('Statement')->icon(Heroicon::OutlinedDocumentText)->color('gray')
                ->visible(fn () => Erp::canAnywhere('receivables.view'))
                ->schema([DatePicker::make('from')->default(now()->startOfMonth())->required(), DatePicker::make('to')->default(now())->required()])
                ->action(fn (Customer $record, array $data, $livewire) => $livewire->redirect(route('print.statement', ['customer' => $record->id, 'from' => $data['from'], 'to' => $data['to']]))),
            Action::make('credit')->label('Credit')->icon(Heroicon::OutlinedCreditCard)->color('warning')
                ->visible(fn () => Erp::can('customers.credit.manage') && config('erp.node.role') !== 'branch')
                ->fillForm(fn (Customer $record) => ['credit_limit' => $record->credit_limit_minor === null ? null : Money::format($record->credit_limit_minor, self::currency()),
                    'payment_terms_days' => $record->payment_terms_days, 'credit_hold' => $record->credit_hold])
                ->schema([
                    TextInput::make('credit_limit')->label('Credit limit')->numeric()->minValue(0)->helperText('Blank = no credit (cash only).'),
                    TextInput::make('payment_terms_days')->label('Payment terms (days)')->numeric()->minValue(0)->maxValue(365)->required(),
                    Toggle::make('credit_hold')->label('Stop credit (credit hold)'),
                    TextInput::make('reason')->required()->maxLength(255),
                ])
                ->action(fn (Customer $record, array $data) => Erp::run(fn ($actor) => app(CustomerService::class)->setCredit($actor, $record,
                    $data['credit_limit'] === null || $data['credit_limit'] === '' ? null : Money::toMinor((string) $data['credit_limit'], self::currency()),
                    (int) $data['payment_terms_days'], (bool) $data['credit_hold'], $data['reason']), 'Credit updated')),
            Action::make('opening')->label('Opening balance')->icon(Heroicon::OutlinedArrowDownOnSquare)->color('gray')
                ->visible(fn (Customer $record) => Erp::can('customers.credit.manage') && config('erp.node.role') !== 'branch' && $record->balance === null)
                ->modalDescription('What the customer owed when you started using this system (from your old books). Once only.')
                ->schema([TextInput::make('amount')->required()->numeric(), DatePicker::make('as_of')->required()->default(now())])
                ->action(fn (Customer $record, array $data) => Erp::run(fn ($actor) => app(CustomerService::class)->openingBalance($actor, $record,
                    Money::toMinor((string) $data['amount'], self::currency()), $data['as_of']), 'Opening balance recorded')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->description(fn (Customer $record) => $record->contact_name),
                TextColumn::make('phone')->searchable()->placeholder('—'),
                TextColumn::make('priceList.name')->label('Price list')->placeholder('default'),
                TextColumn::make('balance.balance_minor')->label('Balance')->alignEnd()->formatStateUsing(fn ($state) => self::money((int) $state))->placeholder('—'),
                TextColumn::make('credit_limit_minor')->label('Credit limit')->alignEnd()->formatStateUsing(fn ($state) => self::money($state))->placeholder('cash')
                    ->description(fn (Customer $record) => $record->credit_hold ? 'on hold' : null),
            ])
            ->filters([
                SelectFilter::make('type')->options(Customer::TYPES),
                Filter::make('owing')->label('Owes money')->query(fn (Builder $q) => $q->whereHas('balance', fn ($b) => $b->where('balance_minor', '>', 0))),
                Filter::make('credit')->label('Credit customers')->query(fn (Builder $q) => $q->whereNotNull('credit_limit_minor')),
            ])
            ->defaultSort('name')
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
