<?php

namespace App\Filament\Resources\TillDrawerEvents\Pages;

use App\Filament\Resources\TillDrawerEvents\TillDrawerEventResource;
use Filament\Resources\Pages\ListRecords;

class ListTillDrawerEvents extends ListRecords
{
    protected static string $resource = TillDrawerEventResource::class;

    protected static ?string $title = 'Till drawer';
}
