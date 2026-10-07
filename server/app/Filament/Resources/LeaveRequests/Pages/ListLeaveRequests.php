<?php

namespace App\Filament\Resources\LeaveRequests\Pages;

use App\Domain\Hr\HrService;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Support\Erp;
use App\Models\Employee;
use App\Models\LeaveType;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListLeaveRequests extends ListRecords
{
    protected static string $resource = LeaveRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('record')->label('Record leave for someone')->icon(Heroicon::OutlinedPlus)->visible(fn () => Erp::canAnywhere('hr.manage'))
                ->schema(LeaveRequestResource::requestForm(chooseEmployee: true))
                ->action(fn (array $data) => Erp::run(fn ($actor) => app(HrService::class)->requestLeave($actor, Employee::findOrFail($data['employee_id']),
                    LeaveType::findOrFail($data['leave_type_id']), $data['starts_on'], $data['ends_on'], $data['reason'] ?? null, $data['document_path'] ?? null), 'Leave requested')),
        ];
    }
}
