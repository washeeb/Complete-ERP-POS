<?php

namespace App\Filament\Resources\Branches\Pages;

use App\Domain\Org\OrgService;
use App\Filament\Resources\Branches\BranchResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditBranch extends EditRecord
{
    protected static string $resource = BranchResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return Erp::run(fn ($actor) => app(OrgService::class)->updateBranch($actor, $record, $data, $data['reason'] ?? null));
    }
}
