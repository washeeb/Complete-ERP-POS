<?php

namespace App\Filament\Resources\Suppliers;

use App\Domain\Payables\SupplierPaymentService;
use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Models\Supplier;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class SupplierResource extends Resource
{
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
        return Erp::can('suppliers.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return Erp::can('suppliers.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return false; // set inactive instead: orders and receipts refer to suppliers
    }

    public static function toFormData(Supplier $s): array
    {
        return ['products' => $s->products()->get()->map(fn ($p) => [
            'product_uom_id' => $p->product_uom_id, 'supplier_sku' => $p->supplier_sku, 'min_order_qty' => Fields::qty($p->min_order_qty),
            'last_cost' => $p->last_cost_minor === null ? null : Money::format($p->last_cost_minor, $s->currency_code), 'is_preferred' => $p->is_preferred,
        ])->all()];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(3)->schema([
                TextInput::make('code')->required()->maxLength(20)->helperText('Short code, e.g. NESTLE'),
                TextInput::make('name')->required()->maxLength(255)->columnSpan(2),
                TextInput::make('contact_name')->label('Contact person')->maxLength(255),
                TextInput::make('phone')->tel()->maxLength(30),
                TextInput::make('email')->email()->maxLength(255),
                TextInput::make('tax_no')->label('TIN / VAT number')->maxLength(40),
                TextInput::make('payment_terms_days')->label('Payment terms (days)')->numeric()->minValue(0)->maxValue(365)->default(0),
                TextInput::make('lead_time_days')->label('Delivery lead time (days)')->numeric()->minValue(0)->maxValue(365),
                Textarea::make('address')->columnSpan(2),
                Toggle::make('is_active')->label('Active')->default(true)->inline(false),
                Textarea::make('notes')->columnSpanFull(),
            ]),
            Section::make('Products supplied')->description('The unit you buy in (e.g. a carton of 24), their code and your last cost before tax. Purchase orders fill in from here.')
                ->schema([
                    Repeater::make('products')->hiddenLabel()->columns(6)->defaultItems(0)->addActionLabel('Add product')->schema([
                        Fields::productUnit()->columnSpan(2),
                        TextInput::make('supplier_sku')->label('Their code')->maxLength(60),
                        TextInput::make('last_cost')->label('Cost per unit')->numeric()->minValue(0),
                        TextInput::make('min_order_qty')->label('Minimum order')->numeric()->minValue(0)->default('0'),
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
                TextColumn::make('phone')->placeholder('—'),
                TextColumn::make('payment_terms_days')->label('Terms')->formatStateUsing(fn ($state) => $state ? "{$state} days" : 'Cash'),
                TextColumn::make('products_count')->counts('products')->label('Products'),
                TextColumn::make('owed')->label('We owe')->alignEnd()->visible(fn () => Erp::canAnywhere('payables.view') && config('erp.node.role') !== 'branch')
                    ->state(fn (Supplier $r) => (int) DB::table('supplier_balances')->where('supplier_id', $r->id)->value('balance_minor'))
                    ->formatStateUsing(fn ($state) => $state ? FinanceUi::money((int) $state) : '—'),
                IconColumn::make('is_active')->boolean()->label('Active'),
            ])
            ->filters([TernaryFilter::make('is_active')->label('Active')->default(true)])
            ->recordActions([
                EditAction::make(),
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

    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/create'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }
}
