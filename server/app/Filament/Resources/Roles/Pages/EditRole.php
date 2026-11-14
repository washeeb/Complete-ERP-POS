<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Domain\Access\AccessService;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['permissions'] = $this->record->permissionKeys();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $role = Erp::run(fn ($actor) => app(AccessService::class)
            ->saveRole($actor, $record, $data, $data['permissions'] ?? [], $data['reason'] ?? null));
        if ($role === null) { // IT Admin: sent for approval; the role stays as it is until then
            $this->redirect(RoleResource::getUrl('edit', ['record' => $record]));

            throw new Halt;
        }

        return $role;
    }
}
