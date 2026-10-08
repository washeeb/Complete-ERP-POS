<?php

namespace App\Filament\Resources\Memberships\Pages;

use App\Domain\Services\MembershipService;
use App\Filament\Resources\Memberships\MembershipResource;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\MembershipPlan;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMemberships extends ListRecords
{
    protected static string $resource = MembershipResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Sign up a customer')->modalDescription('The first period is invoiced straight away (Receivables → Invoices).')
            ->using(fn (array $data) => Erp::run(fn ($actor) => app(MembershipService::class)->enrol($actor, Customer::findOrFail($data['customer_id']),
                MembershipPlan::findOrFail($data['membership_plan_id']), Branch::findOrFail($data['branch_id']), $data['starts_on'] ?? null,
                filled($data['price'] ?? null) ? FinanceUi::toMinor($data['price']) : null)))];
    }
}
