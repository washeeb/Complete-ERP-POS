<?php

namespace App\Filament\Resources\Vans\Pages;

use App\Domain\Distribution\VanService;
use App\Filament\Resources\Vans\VanResource;
use App\Filament\Support\Erp;
use App\Models\Branch;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVans extends ListRecords
{
    protected static string $resource = VanResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New van')->modalDescription('Creates the van as a warehouse with its own till. Then register the rep\'s phone as a POS device on that till (System → Devices).')
            ->using(fn (array $data) => Erp::run(fn ($actor) => app(VanService::class)->create($actor, Branch::findOrFail($data['branch_id']), $data)))];
    }
}
