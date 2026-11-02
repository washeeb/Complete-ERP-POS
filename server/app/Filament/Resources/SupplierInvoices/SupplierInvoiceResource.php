<?php

namespace App\Filament\Resources\SupplierInvoices;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\InvoiceScanner;
use App\Domain\Purchasing\SupplierPortal;
use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Filament\Resources\SupplierInvoices\Pages\ListSupplierInvoices;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\SupplierBill;
use App\Models\SupplierInvoice;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Invoices suppliers sent through the supplier portal: accounts enters each as a supplier bill (matched to the goods
 * received as usual), then marks it entered, or turns it back with the reason the supplier sees.
 */
class SupplierInvoiceResource extends Resource
{
    protected static ?string $model = SupplierInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?string $navigationLabel = 'Invoices from suppliers';

    protected static ?string $modelLabel = 'invoice from a supplier';

    protected static ?string $pluralModelLabel = 'invoices from suppliers';

    protected static ?int $navigationSort = 7;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('payables.view') || Erp::canAnywhere('payables.manage');
    }

    public static function canCreate(): bool
    {
        return false; // they come from the supplier portal
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
        $n = SupplierInvoice::where('status', 'received')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        $portal = fn () => app(SupplierPortal::class);
        $waiting = fn (SupplierInvoice $r) => $r->status === 'received' && Erp::canAnywhere('payables.manage');

        return $table
            ->columns([
                TextColumn::make('created_at')->label('Received')->dateTime('j M Y H:i')->sortable(),
                TextColumn::make('supplier.name')->label('Supplier')->searchable(),
                TextColumn::make('invoice_no')->label('Their invoice')->searchable(),
                TextColumn::make('invoice_date')->label('Dated')->date(),
                TextColumn::make('amount_minor')->label('Amount')->alignEnd()->formatStateUsing(fn ($state, SupplierInvoice $r) => Money::display((int) $state, $r->currency_code)),
                TextColumn::make('purchaseOrder.number')->label('For order')->placeholder('—'),
                TextColumn::make('sent_by_name')->label('Sent by'),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => SupplierInvoice::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'received' => 'warning', 'entered' => 'success', default => 'gray'
                    })
                    ->description(fn (SupplierInvoice $r) => $r->status === 'entered' ? ($r->bill?->number ?? null) : ($r->status === 'rejected' ? $r->reject_reason : null)),
            ])
            ->filters([SelectFilter::make('status')->options(SupplierInvoice::STATUSES)->default('received')])
            ->recordActions([
                Action::make('open')->label('Open the invoice')->icon(Heroicon::OutlinedDocumentText)->color('gray')
                    ->visible(fn (SupplierInvoice $r) => (bool) $r->file_path)->url(fn (SupplierInvoice $r) => route('supplier-invoice.file', $r), shouldOpenInNewTab: true),
                Action::make('read')->label('Read with AI')->icon(Heroicon::OutlinedSparkles)->color('primary')
                    ->visible(fn (SupplierInvoice $r) => $waiting($r) && $r->file_path && app(AiGateway::class)->unavailableReason($r->tenant_id, 'vision') === null)
                    ->requiresConfirmation()->modalDescription('The AI reads the invoice into a draft bill, matched to what was received or confirmed on their orders. You check every line before creating it.')
                    ->action(function (SupplierInvoice $r, $livewire) {
                        $branch = Branch::find($r->purchaseOrder?->branch_id) ?? Branch::where('is_hq', true)->first();
                        $doc = Erp::run(fn ($actor) => app(InvoiceScanner::class)->scan($actor, $branch, $r->file_path, (string) ($r->file_name ?? 'invoice'), $r->supplier, $r->id));
                        if ($doc) {
                            $livewire->redirect(SupplierBillResource::getUrl('create', ['scan' => $doc->id]));
                        }
                    }),
                Action::make('enter')->label('Enter as bill')->icon(Heroicon::OutlinedPencilSquare)->color('gray')->visible($waiting)
                    ->url(fn (SupplierInvoice $r) => SupplierBillResource::getUrl('create', ['invoice' => $r->id])),
                Action::make('entered')->label('Mark entered')->icon(Heroicon::OutlinedCheck)->color('success')->visible($waiting)
                    ->modalDescription('For an invoice already entered another way: choose its bill. (Enter as bill and Read with AI mark it entered by themselves.)')
                    ->schema(fn (SupplierInvoice $r) => [Select::make('bill')->label('The supplier bill')->searchable()
                        ->options(SupplierBill::where('supplier_id', $r->supplier_id)->orderByDesc('created_at')->limit(50)->get()
                            ->mapWithKeys(fn (SupplierBill $b) => [$b->id => "{$b->number} · {$b->supplier_invoice_no} · ".Money::display((int) $b->total_minor, $b->currency_code)]))])
                    ->action(fn (SupplierInvoice $r, array $data) => Erp::run(fn ($actor) => $portal()->markEntered($actor, $r, $data['bill'] ?? null), 'Marked entered')),
                Action::make('reject')->label('Turn back')->icon(Heroicon::OutlinedArrowUturnLeft)->color('danger')->visible($waiting)
                    ->schema([TextInput::make('reason')->label('Why (the supplier sees this)')->required()->maxLength(255)
                        ->placeholder('e.g. Prices differ from order PO-00012; send a corrected invoice')])
                    ->action(fn (SupplierInvoice $r, array $data) => Erp::run(fn ($actor) => $portal()->reject($actor, $r, $data['reason']), 'Turned back')),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No invoices from suppliers')
            ->emptyStateDescription('Suppliers send invoices from their supplier portal page (System → Settings → Purchasing → Supplier portal).');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['supplier', 'purchaseOrder', 'bill']);
    }

    public static function getPages(): array
    {
        return ['index' => ListSupplierInvoices::route('/')];
    }
}
