<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Domain\Services\AppointmentService;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Support\Erp;
use App\Models\Appointment;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAppointments extends ListRecords
{
    protected static string $resource = AppointmentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Book appointment')
            ->using(fn (array $data) => Erp::run(fn ($actor) => app(AppointmentService::class)->book($actor, new Appointment, array_filter($data, fn ($v) => $v !== null && $v !== ''))))];
    }
}
