<?php

namespace App\Filament\Vendor\Resources\Checkins\Pages;

use App\Filament\Vendor\Resources\Checkins\CheckinResource;
use Filament\Resources\Pages\ListRecords;

class ListCheckins extends ListRecords
{
    protected static string $resource = CheckinResource::class;
}
