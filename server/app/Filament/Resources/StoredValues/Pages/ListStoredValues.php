<?php

namespace App\Filament\Resources\StoredValues\Pages;

use App\Filament\Resources\StoredValues\StoredValueResource;
use Filament\Resources\Pages\ListRecords;

class ListStoredValues extends ListRecords
{
    protected static string $resource = StoredValueResource::class;
}
