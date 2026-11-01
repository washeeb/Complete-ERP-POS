<?php

namespace App\Filament\Resources\SupplierBills\Pages;

use App\Domain\Ai\InvoiceScanner;
use App\Domain\Payables\BillService;
use App\Domain\Purchasing\SupplierPortal;
use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\AiDocument;
use App\Models\Branch;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;

class CreateSupplierBill extends CreateRecord
{
    protected static string $resource = SupplierBillResource::class;

    /** A scanned invoice that pre-fills the form (Scan an invoice). */
    #[Url]
    public ?string $scan = null;

    /** An invoice sent on the supplier portal, entered as this bill (marked entered when the bill is made). */
    #[Url]
    public ?string $invoice = null;

    protected function fillForm(): void
    {
        $doc = $this->scan ? AiDocument::where('status', 'read')->find($this->scan) : null;
        $sent = $this->invoice ? SupplierInvoice::where('status', 'received')->find($this->invoice) : null;
        match (true) {
            (bool) $doc => $this->form->fill($doc->form),
            (bool) $sent => $this->form->fill(['supplier_id' => $sent->supplier_id, 'supplier_invoice_no' => $sent->invoice_no, 'bill_date' => $sent->invoice_date->toDateString(),
                'branch_id' => $sent->purchaseOrder?->branch_id ?? FinanceUi::hq(), 'note' => "Sent on the supplier portal by {$sent->sent_by_name}", 'stock_lines' => [],
                'order_lines' => [], 'expense_lines' => []]),
            default => parent::fillForm(),
        };
    }

    public function getSubheading(): string|Htmlable|null
    {
        if ($this->invoice && ! $this->scan) {
            return 'From the invoice the supplier sent: add the lines from it (open it from Invoices from suppliers). When you create the bill, that invoice is marked entered.';
        }
        if (! $this->scan) {
            return null;
        }
        $checks = ($doc = AiDocument::find($this->scan)) ? InvoiceScanner::checks($doc) : [];

        return new HtmlString(e('Filled in from the scanned invoice: check the supplier, every line and the totals against the paper before you create the bill.')
            .($checks ? '<ul style="margin-top:.4rem;color:rgb(217 119 6);list-style:disc;padding-left:1.2rem">'.implode('', array_map(fn ($c) => '<li>'.e($c).'</li>', $checks)).'</ul>' : ''));
    }

    protected function handleRecordCreation(array $data): Model
    {
        $bill = Erp::run(fn ($actor) => app(BillService::class)->create($actor, Supplier::findOrFail($data['supplier_id']), Branch::findOrFail($data['branch_id']),
            SupplierBillResource::toDomain($data)));
        if ($bill && $this->scan && ($doc = AiDocument::find($this->scan))) {
            app(InvoiceScanner::class)->used($doc, $bill->id);
        }
        $sentId = $this->invoice ?? ($this->scan ? (AiDocument::find($this->scan)?->form['supplier_invoice_id'] ?? null) : null);
        if ($bill && $sentId && ($sent = SupplierInvoice::where('status', 'received')->find($sentId))) {
            Erp::run(fn ($actor) => app(SupplierPortal::class)->markEntered($actor, $sent, $bill->id));
        }

        return $bill;
    }

    protected function getRedirectUrl(): string
    {
        return SupplierBillResource::getUrl('view', ['record' => $this->record]);
    }
}
