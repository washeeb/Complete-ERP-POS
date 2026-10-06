<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Domain\Customers\CustomerService;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\Erp;
use App\Models\Customer;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $source = $data['consent_source'] ?? 'in person';

        return Erp::run(fn ($actor) => app(CustomerService::class)->save($actor, new Customer, CustomerResource::toDomain($data), $source));
    }

    protected function getRedirectUrl(): string
    {
        return CustomerResource::getUrl('view', ['record' => $this->record]);
    }
}
