<?php

namespace App\Filament\Auth;

use App\Domain\Access\AccessService;
use App\Filament\Support\Erp;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

/** Filament's profile page, but saving through the domain service so the change is audited. */
class EditProfile extends BaseEditProfile
{
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        return Erp::run(fn ($actor) => app(AccessService::class)->updateOwnProfile($actor, $record, $data));
    }
}
