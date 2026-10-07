<?php

namespace App\Filament\Resources\Reservations\Pages;

use App\Domain\Restaurant\ReservationService;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Filament\Support\Erp;
use App\Models\Reservation;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListReservations extends ListRecords
{
    protected static string $resource = ReservationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New booking')->using(fn (array $data) => Erp::run(fn ($actor) => app(ReservationService::class)->save($actor, new Reservation, $data)))];
    }
}
