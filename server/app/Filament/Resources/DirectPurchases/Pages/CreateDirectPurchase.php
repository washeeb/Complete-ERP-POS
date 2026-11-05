<?php

namespace App\Filament\Resources\DirectPurchases\Pages;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\InvoiceScanner;
use App\Domain\Purchasing\DirectPurchases;
use App\Filament\Resources\DirectPurchases\DirectPurchaseResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\DirectPurchase;
use App\Models\Warehouse;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class CreateDirectPurchase extends CreateRecord
{
    protected static string $resource = DirectPurchaseResource::class;

    protected static ?string $title = 'Enter a supplier invoice';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('scan')->label('Scan the invoice')->icon(Heroicon::OutlinedCamera)->color('gray')
                ->modalDescription(fn () => app(AiGateway::class)->unavailableReason(Erp::user()->tenant_id, 'vision')
                    ?? 'The AI reads every page and fills in this form. Nothing is entered until you check it against the paper and save.')
                ->schema([
                    FileUpload::make('files')->label('Photos or PDF, every page')->multiple()->required()->disk('local')->directory('direct-purchases')->visibility('private')
                        ->acceptedFileTypes(InvoiceScanner::TYPES)->maxSize(10240),
                ])
                ->action(function (array $data) {
                    $warehouse = Warehouse::find(array_key_first(Fields::warehouses('purchasing.direct')));
                    $form = $warehouse ? Erp::run(fn ($actor) => app(InvoiceScanner::class)->scanPurchase($actor, $warehouse->branch()->first(), array_values($data['files']))) : null;
                    if ($form) {
                        $this->form->fill($form + $this->form->getRawState());
                        Notification::make()->success()->title('Read: check every line against the invoice')->send();
                    }
                }),
        ];
    }

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(DirectPurchases::class)->save($actor, new DirectPurchase, $data));
    }

    protected function getRedirectUrl(): string
    {
        return DirectPurchaseResource::getUrl('view', ['record' => $this->record]);
    }
}
