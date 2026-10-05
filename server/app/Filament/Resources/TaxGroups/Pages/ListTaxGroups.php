<?php

namespace App\Filament\Resources\TaxGroups\Pages;

use App\Filament\Resources\TaxGroups\TaxGroupResource;
use Filament\Resources\Pages\ListRecords;

class ListTaxGroups extends ListRecords
{
    protected static string $resource = TaxGroupResource::class;
}
