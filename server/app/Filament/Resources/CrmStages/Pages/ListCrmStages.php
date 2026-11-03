<?php

namespace App\Filament\Resources\CrmStages\Pages;

use App\Filament\Resources\CrmStages\CrmStageResource;
use Filament\Resources\Pages\ListRecords;

class ListCrmStages extends ListRecords
{
    protected static string $resource = CrmStageResource::class;

    public function getSubheading(): ?string
    {
        return 'The steps a deal goes through, in order, each with its chance of winning. Keep one Won and one Lost stage.';
    }
}
