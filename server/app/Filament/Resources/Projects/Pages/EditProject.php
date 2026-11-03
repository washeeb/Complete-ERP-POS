<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Domain\Projects\Projects;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\Erp;
use App\Filament\Support\ProjectUi;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProject extends EditRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return ProjectResource::projectActions();
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $r = $this->record;
        $data['budget_cost'] = $r->budget_cost_minor === null ? null : $r->budget_cost_minor / 100;
        $data['contract'] = $r->contract_minor === null ? null : $r->contract_minor / 100;
        $data['hourly_rate'] = $r->hourly_rate_minor === null ? null : $r->hourly_rate_minor / 100;
        $data['markup'] = $r->materials_markup_bp / 100;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return Erp::run(fn ($actor) => app(Projects::class)->save($actor, $record, ProjectResource::toDomain($data)));
    }

    /** People who only see projects get the page without its save button. */
    protected function getFormActions(): array
    {
        return $this->record->isOpen() && ProjectUi::can('projects.manage') ? parent::getFormActions() : [];
    }
}
