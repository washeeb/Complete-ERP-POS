<?php

namespace App\Filament\Resources\Invoices;

use App\Domain\Sales\ReceivablesService;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\Invoice;
use App\Models\Warehouse;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Customer invoices: read-only once posted; corrected with credit notes. */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Invoices';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('sales.orders.view') || Erp::canAnywhere('receivables.view');
    }

    public static function canCreate(): bool
    {
        return false; // raised by deliveries
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
        $a = Erp::branchIds('sales.orders.view');
        $b = Erp::branchIds('receivables.view');
        $ids = $a === null || $b === null ? null : array_values(array_unique([...$a, ...$b]));

        return parent::getEloquentQuery()->with('customer')->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function money(?int $minor, Invoice $i): string
    {
        return Money::display((int) $minor, $i->currency_code);
    }

    public static function openMinor(Invoice $i): int
    {
        return app(ReceivablesService::class)->openAmounts($i->customer_id)[$i->id] ?? 0;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('Invoice')->weight('bold'),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => Invoice::STATUSES[$state] ?? $state),
                TextEntry::make('customer_name')->label('Customer')->belowContent(fn (Invoice $record) => $record->customer_tax_no ? "TIN {$record->customer_tax_no}" : null),
                TextEntry::make('customer_ref')->label('Customer ref.')->placeholder('—'),
                TextEntry::make('issued_on')->label('Issued')->date(),
                TextEntry::make('due_on')->label('Due')->date()->color(fn (Invoice $record) => $record->due_on->isPast() && self::openMinor($record) > 0 ? 'danger' : null),
                TextEntry::make('total_minor')->label('Total')->weight('bold')->formatStateUsing(fn ($state, Invoice $record) => self::money($state, $record)),
                TextEntry::make('open')->label('Still owed')->state(fn (Invoice $record) => self::money(self::openMinor($record), $record)),
                TextEntry::make('tax')->label('Tax')->state(fn (Invoice $record) => collect($record->tax_breakdown)->map(fn ($t) => "{$t['name']} ".self::money($t['amount_minor'], $record))->implode(' · ') ?: '—')
                    ->columnSpanFull(),
            ]),
            Section::make('Lines')->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->columns(5)->schema([
                    TextEntry::make('name')->columnSpan(2)->formatStateUsing(fn ($state, $record) => "{$record->sku} · {$state}"),
                    TextEntry::make('qty')->formatStateUsing(fn ($state, $record) => Fields::qty($state, $record->uom_code)),
                    TextEntry::make('unit_price_minor')->label('Price')->formatStateUsing(fn ($state, $livewire) => self::money($state, $livewire->getRecord())),
                    TextEntry::make('total_minor')->label('Line total')->formatStateUsing(fn ($state, $livewire) => self::money($state, $livewire->getRecord())),
                ]),
            ]),
        ]);
    }

    /** @return list<Action> */
    public static function invoiceActions(): array
    {
        return [
            Action::make('print')->label('Print / PDF')->icon(Heroicon::OutlinedPrinter)->color('gray')
                ->url(fn (Invoice $record) => route('print.invoice', $record), shouldOpenInNewTab: true),
            Action::make('credit')->label('Credit note')->icon(Heroicon::OutlinedReceiptRefund)->color('danger')
                ->visible(fn (Invoice $record) => $record->status === 'posted' && Erp::can('receivables.credit_note', $record->branch))
                ->modalDescription('Cancels this invoice on the customer’s account. If it was already paid, the money stays on the account as credit.')
                ->schema(fn (Invoice $record) => [
                    TextInput::make('reason')->required()->maxLength(255),
                    Select::make('warehouse_id')->label('Goods returned into (optional)')->placeholder('No goods come back')
                        ->options(fn () => Fields::warehouses('sales.deliver')),
                ])
                ->action(fn (Invoice $record, array $data) => Erp::run(fn ($actor) => app(ReceivablesService::class)->creditNote($actor, $record, $data['reason'],
                    isset($data['warehouse_id']) ? Warehouse::find($data['warehouse_id']) : null), 'Credit note issued')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Invoice')->searchable()->sortable(),
                TextColumn::make('customer_name')->label('Customer')->searchable(),
                TextColumn::make('customer_ref')->label('Ref.')->searchable()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('issued_on')->label('Issued')->date()->sortable(),
                TextColumn::make('due_on')->label('Due')->date()->sortable(),
                TextColumn::make('total_minor')->label('Total')->alignEnd()->formatStateUsing(fn ($state, Invoice $record) => self::money($state, $record)),
                TextColumn::make('open')->label('Owed')->alignEnd()->state(fn (Invoice $record) => self::openMinor($record))
                    ->formatStateUsing(fn ($state, Invoice $record) => $state ? self::money($state, $record) : 'paid')
                    ->color(fn ($state, Invoice $record) => $state && $record->due_on->isPast() ? 'danger' : ($state ? null : 'success')),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => Invoice::STATUSES[$state] ?? $state),
            ])
            ->filters([
                SelectFilter::make('status')->options(Invoice::STATUSES),
                Filter::make('overdue')->label('Past due date')->query(fn (Builder $q) => $q->where('status', 'posted')->whereDate('due_on', '<', now())),
            ])
            ->defaultSort('issued_on', 'desc')
            ->recordActions([ViewAction::make(), ...self::invoiceActions()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListInvoices::route('/'), 'view' => ViewInvoice::route('/{record}')];
    }
}
