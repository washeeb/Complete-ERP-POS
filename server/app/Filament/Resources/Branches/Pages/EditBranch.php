<?php

namespace App\Filament\Resources\Branches\Pages;

use App\Domain\Org\OrgService;
use App\Filament\Resources\Branches\BranchResource;
use App\Filament\Support\Erp;
use App\Support\Settings;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditBranch extends EditRecord
{
    protected static string $resource = BranchResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach (Settings::branchKeys() as $key) {
            $value = $this->record->settings[$key] ?? null;
            $data[BranchResource::ownField($key)] = is_bool($value) ? ($value ? '1' : '0') : $value;
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $own = [];
        foreach (Settings::branchKeys() as $key) {
            $field = BranchResource::ownField($key);
            if (array_key_exists($field, $data)) {
                $own[$key] = $data[$field];
                unset($data[$field]);
            } else {
                $own[$key] = $record->settings[$key] ?? null; // section hidden (module off): keep what the branch had
            }
        }

        return Erp::run(fn ($actor) => app(OrgService::class)->updateBranch($actor, $record, $data + ['settings' => $own], $data['reason'] ?? null));
    }
}
