<?php

namespace App\Filament\Resources\Branches\Pages;

use App\Domain\Org\OrgService;
use App\Filament\Resources\Branches\BranchResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateBranch extends CreateRecord
{
    protected static string $resource = BranchResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(OrgService::class)->createBranch($actor, $data, (int) $data['tills']));
    }
}
