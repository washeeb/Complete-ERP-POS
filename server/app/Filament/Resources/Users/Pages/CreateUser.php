<?php

namespace App\Filament\Resources\Users\Pages;

use App\Domain\Access\AccessService;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $user = Erp::run(fn ($actor) => app(AccessService::class)->createUser($actor, $data));
        if ($user === null) { // IT Admin: sent to the owner or SyncLytics support for approval
            $this->redirect(UserResource::getUrl('index'));

            throw new Halt;
        }

        return $user;
    }

    protected function getRedirectUrl(): string
    {
        // Straight to the record so access can be granted next.
        return UserResource::getUrl('edit', ['record' => $this->record]);
    }
}
