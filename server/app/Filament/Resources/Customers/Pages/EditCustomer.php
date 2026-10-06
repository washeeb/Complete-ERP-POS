<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Domain\Customers\CustomerService;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['consents'] = collect($this->record->consents ?? [])->map(fn ($c) => (bool) ($c['granted'] ?? false))->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $source = $data['consent_source'] ?? 'in person';
        unset($data['credit_limit_minor'], $data['payment_terms_days'], $data['credit_hold']); // changed with “Credit”

        return Erp::run(fn ($actor) => app(CustomerService::class)->save($actor, $record, CustomerResource::toDomain($data), $source));
    }

    protected function getRedirectUrl(): string
    {
        return CustomerResource::getUrl('view', ['record' => $this->record]);
    }
}
