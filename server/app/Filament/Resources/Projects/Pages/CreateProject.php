<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Domain\Projects\Projects;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\Erp;
use App\Models\Project;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(Projects::class)->save($actor, new Project, ProjectResource::toDomain($data)));
    }

    protected function getRedirectUrl(): string
    {
        return ProjectResource::getUrl('edit', ['record' => $this->record]);
    }
}
