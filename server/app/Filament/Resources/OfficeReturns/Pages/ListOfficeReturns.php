<?php

namespace App\Filament\Resources\OfficeReturns\Pages;

use App\Filament\Resources\OfficeReturns\OfficeReturnResource;
use Filament\Resources\Pages\ListRecords;

class ListOfficeReturns extends ListRecords
{
    protected static string $resource = OfficeReturnResource::class;

    public function getSubheading(): ?string
    {
        return 'To take goods back here, open the sale under Sales → Sales and click Return goods. A manager then approves it, not the person who entered it.';
    }
}
