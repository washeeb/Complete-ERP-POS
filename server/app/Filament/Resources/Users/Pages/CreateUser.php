<?php

namespace App\Filament\Resources\Users\Pages;

use App\Domain\Access\AccessService;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(AccessService::class)->createUser($actor, $data));
    }

    protected function getRedirectUrl(): string
    {
        // Straight to the record so access can be granted next.
        return UserResource::getUrl('edit', ['record' => $this->record]);
    }
}
