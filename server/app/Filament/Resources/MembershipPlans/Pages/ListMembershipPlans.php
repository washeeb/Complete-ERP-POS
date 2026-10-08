<?php

namespace App\Filament\Resources\MembershipPlans\Pages;

use App\Domain\Services\MembershipService;
use App\Filament\Resources\MembershipPlans\MembershipPlanResource;
use App\Filament\Support\Erp;
use App\Models\MembershipPlan;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMembershipPlans extends ListRecords
{
    protected static string $resource = MembershipPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New plan')->using(fn (array $data) => Erp::run(fn ($actor) => app(MembershipService::class)->savePlan($actor, new MembershipPlan, $data)))];
    }
}
