<?php

namespace App\Filament\Resources\Prescriptions\Pages;

use App\Filament\Resources\Prescriptions\PrescriptionResource;
use Filament\Resources\Pages\ListRecords;

class ListPrescriptions extends ListRecords
{
    protected static string $resource = PrescriptionResource::class;
}
