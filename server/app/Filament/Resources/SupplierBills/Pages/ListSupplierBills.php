<?php

namespace App\Filament\Resources\SupplierBills\Pages;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\InvoiceScanner;
use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Branch;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListSupplierBills extends ListRecords
{
    protected static string $resource = SupplierBillResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('scan')->label('Scan an invoice')->icon(Heroicon::OutlinedCamera)->color('gray')
                ->visible(fn () => Erp::canAnywhere('payables.manage'))
                ->modalDescription(fn () => app(AiGateway::class)->unavailableReason(Erp::user()->tenant_id, 'vision')
                    ?? 'The AI reads the invoice and fills in the bill form. Nothing is entered until you check it and press Create.')
                ->schema([
                    FileUpload::make('file')->label('Photo or PDF of the supplier invoice')->required()->disk('local')->directory('ai-documents')->visibility('private')
                        ->acceptedFileTypes(InvoiceScanner::TYPES)->maxSize(10240)->storeFileNamesIn('file_name'),
                    Select::make('branch_id')->label('Branch')->required()->options(fn () => FinanceUi::branches())->default(fn () => FinanceUi::hq()),
                ])
                ->action(function (array $data) {
                    $doc = Erp::run(fn ($actor) => app(InvoiceScanner::class)->scan($actor, Branch::findOrFail($data['branch_id']), $data['file'], (string) ($data['file_name'] ?? 'invoice')));
                    if ($doc) {
                        $this->redirect(SupplierBillResource::getUrl('create', ['scan' => $doc->id]));
                    }
                }),
            CreateAction::make()->label('Enter a bill'),
        ];
    }
}
