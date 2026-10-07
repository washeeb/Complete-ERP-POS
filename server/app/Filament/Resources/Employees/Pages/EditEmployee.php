<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Domain\Hr\HrService;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return EmployeeResource::toForm($this->record);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return Erp::run(fn ($actor) => app(HrService::class)->saveEmployee($actor, $record, EmployeeResource::toDomain($data)));
    }

    protected function getHeaderActions(): array
    {
        return [EmployeeResource::terminateAction()];
    }
}
