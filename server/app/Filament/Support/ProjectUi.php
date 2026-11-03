<?php

namespace App\Filament\Support;

use App\Domain\Projects\Projects;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Builder;

/** What the project pages share: who may see them (the add-on and the rights) and the branches shown. */
final class ProjectUi
{
    public static function can(string $permission): bool
    {
        return Projects::enabled() && Erp::canAnywhere($permission);
    }

    /** Only projects (or rows with a project_id) of the branches the person sees projects for. */
    public static function scope(Builder $q, string $column = 'branch_id'): Builder
    {
        $b = Erp::branchIds('projects.view');

        return $b === null ? $q : $q->whereIn($column, $b);
    }

    /** @return array<string, string> */
    public static function branches(string $permission = 'projects.manage'): array
    {
        $b = Erp::branchIds($permission);

        return Branch::where('is_active', true)->when($b !== null, fn ($q) => $q->whereIn('id', $b))->orderBy('code')->pluck('name', 'id')->all();
    }
}
