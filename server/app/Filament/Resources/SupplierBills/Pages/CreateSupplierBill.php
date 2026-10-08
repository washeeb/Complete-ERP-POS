<?php

namespace App\Filament\Resources\SupplierBills\Pages;

use App\Domain\Ai\InvoiceScanner;
use App\Domain\Payables\BillService;
use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Filament\Support\Erp;
use App\Models\AiDocument;
use App\Models\Branch;
use App\Models\Supplier;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Url;

class CreateSupplierBill extends CreateRecord
{
    protected static string $resource = SupplierBillResource::class;

    /** A scanned invoice that pre-fills the form (Scan an invoice). */
    #[Url]
    public ?string $scan = null;

    protected function fillForm(): void
    {
        $doc = $this->scan ? AiDocument::where('status', 'read')->find($this->scan) : null;
        $doc ? $this->form->fill($doc->form) : parent::fillForm();
    }

    public function getSubheading(): ?string
    {
        return $this->scan ? 'Filled in from the scanned invoice: check the supplier, every line and the totals against the paper before you create the bill.' : null;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $bill = Erp::run(fn ($actor) => app(BillService::class)->create($actor, Supplier::findOrFail($data['supplier_id']), Branch::findOrFail($data['branch_id']),
            SupplierBillResource::toDomain($data)));
        if ($bill && $this->scan && ($doc = AiDocument::find($this->scan))) {
            app(InvoiceScanner::class)->used($doc, $bill->id);
        }

        return $bill;
    }

    protected function getRedirectUrl(): string
    {
        return SupplierBillResource::getUrl('view', ['record' => $this->record]);
    }
}
