<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\InvoiceScanner;
use App\Domain\Purchasing\OrderImporter;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\Supplier;
use App\Models\Warehouse;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

class ListPurchaseOrders extends ListRecords
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // A draft order from a file: an Excel/CSV list, or a supplier's pro-forma read by the AI. A person checks it before submitting.
            Action::make('import')->label('Order from a file')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')
                ->visible(fn () => Erp::canAnywhere('purchasing.manage'))
                ->modalDescription('Makes a draft purchase order to check, change and submit as usual. Lines that cannot be matched to a product, or have no cost, are listed, not guessed.')
                ->schema([
                    Radio::make('source')->label('From')->required()->default('sheet')->live()->options([
                        'sheet' => 'An Excel or CSV list (columns: SKU, barcode or product name; Qty; optional Unit and Cost)',
                        'proforma' => "A supplier's pro-forma invoice — photo or PDF, read by the AI",
                    ]),
                    Select::make('supplier_id')->label('Supplier')->searchable()->options(fn () => Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                        ->required(fn (Get $get) => $get('source') === 'sheet')
                        ->helperText(fn (Get $get) => $get('source') === 'proforma' ? 'Leave empty to take it from the pro-forma.' : null),
                    Select::make('warehouse_id')->label('Deliver to')->required()->options(fn () => Fields::warehouses('purchasing.manage'))
                        ->default(fn () => array_key_first(Fields::warehouses('purchasing.manage'))),
                    FileUpload::make('file')->label('File')->required()->disk('local')->directory('purchase-imports')->visibility('private')->maxSize(10240)
                        ->acceptedFileTypes(fn (Get $get) => $get('source') === 'proforma' ? InvoiceScanner::TYPES : OrderImporter::SHEET_TYPES)
                        ->storeFileNamesIn('file_name')
                        ->helperText(fn (Get $get) => $get('source') === 'proforma' ? app(AiGateway::class)->unavailableReason(Erp::user()->tenant_id, 'vision') : null),
                ])
                ->action(function (array $data) {
                    $warehouse = Warehouse::findOrFail($data['warehouse_id']);
                    $supplier = filled($data['supplier_id'] ?? null) ? Supplier::findOrFail($data['supplier_id']) : null;
                    $name = (string) ($data['file_name'] ?? basename($data['file']));
                    $result = Erp::run(fn ($actor) => $data['source'] === 'proforma'
                        ? app(OrderImporter::class)->fromProforma($actor, $warehouse, $supplier, $data['file'], $name)
                        : app(OrderImporter::class)->fromSheet($actor, $supplier, $warehouse, Storage::disk('local')->path($data['file']), $name));
                    if ($data['source'] === 'sheet') {
                        Storage::disk('local')->delete($data['file']); // the list is in the order now; the pro-forma photo is kept with the AI record
                    }
                    $order = $result['order'];
                    $n = $order->lines->count();
                    $result['skipped']
                        ? Notification::make()->warning()->title("{$order->number}: {$n} line(s) in, ".count($result['skipped']).' left out')->body(implode("\n", array_slice($result['skipped'], 0, 15)))->persistent()->send()
                        : Notification::make()->success()->title("{$order->number}: {$n} line(s) in")->body('Check the lines and costs, then submit it for approval.')->send();
                    $this->redirect(PurchaseOrderResource::getUrl('edit', ['record' => $order]));
                }),
            CreateAction::make()->label('New purchase order'),
        ];
    }
}
