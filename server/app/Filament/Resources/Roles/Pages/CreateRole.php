<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Domain\Access\AccessService;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Support\Erp;
use App\Models\Role;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $data['key'] = Str::slug($data['name'], '_').'_'.Str::lower(Str::random(4)); // never collides with seeded keys

        return Erp::run(fn ($actor) => app(AccessService::class)->saveRole($actor, new Role, $data, $data['permissions'] ?? []));
    }
}
