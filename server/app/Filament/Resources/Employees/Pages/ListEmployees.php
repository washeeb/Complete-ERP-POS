<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Departments\DepartmentResource;
use App\Filament\Resources\Employees\EmployeeResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('departments')->label('Departments')->icon(Heroicon::OutlinedRectangleGroup)->color('gray')->url(DepartmentResource::getUrl()),
            CreateAction::make()->label('Add employee'),
        ];
    }
}
