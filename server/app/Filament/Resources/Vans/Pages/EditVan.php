<?php

namespace App\Filament\Resources\Vans\Pages;

use App\Domain\Distribution\VanService;
use App\Filament\Resources\Vans\VanResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditVan extends EditRecord
{
    protected static string $resource = VanResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return Erp::run(fn ($actor) => app(VanService::class)->update($actor, $record, $data));
    }
}
