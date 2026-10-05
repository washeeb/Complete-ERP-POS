<?php

namespace App\Filament\Resources\ReviewItems\Pages;

use App\Filament\Resources\ReviewItems\ReviewItemResource;
use Filament\Resources\Pages\ListRecords;

class ListReviewItems extends ListRecords
{
    protected static string $resource = ReviewItemResource::class;

    protected static ?string $title = 'Review queue';
}
