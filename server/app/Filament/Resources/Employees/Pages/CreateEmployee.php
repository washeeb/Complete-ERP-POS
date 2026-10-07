<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Domain\Hr\HrService;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Support\Erp;
use App\Models\Employee;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(HrService::class)->saveEmployee($actor, new Employee, EmployeeResource::toDomain($data)));
    }
}
