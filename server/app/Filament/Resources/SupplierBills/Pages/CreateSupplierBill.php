<?php

namespace App\Filament\Resources\SupplierBills\Pages;

use App\Domain\Payables\BillService;
use App\Filament\Resources\SupplierBills\SupplierBillResource;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\Supplier;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSupplierBill extends CreateRecord
{
    protected static string $resource = SupplierBillResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(BillService::class)->create($actor, Supplier::findOrFail($data['supplier_id']), Branch::findOrFail($data['branch_id']),
            SupplierBillResource::toDomain($data)));
    }

    protected function getRedirectUrl(): string
    {
        return SupplierBillResource::getUrl('view', ['record' => $this->record]);
    }
}
