<?php

namespace App\Filament\Support;

use App\Domain\Service\ServiceDesk;
use App\Models\User;
use App\Security\Authorizer;
use Illuminate\Database\Eloquent\Builder;

/** What the service desk and maintenance pages share: the add-on, the rights, the branches and people shown. */
final class ServiceUi
{
    public static function can(string $permission): bool
    {
        return ServiceDesk::enabled() && Erp::canAnywhere($permission);
    }

    /** Tickets the person may see: of their branches (and those without one) — or only their own requests. */
    public static function tickets(Builder $q): Builder
    {
        if (! Erp::canAnywhere('helpdesk.view')) {
            return $q->where('raised_by', Erp::user()?->id);
        }
        $b = Erp::branchIds('helpdesk.view');

        return $b === null ? $q : $q->where(fn ($w) => $w->whereIn('branch_id', $b)->orWhereNull('branch_id'));
    }

    /** Equipment and work orders of the branches the person sees maintenance for (customers' equipment: all). */
    public static function maintenance(Builder $q): Builder
    {
        $b = Erp::branchIds('maintenance.view');

        return $b === null ? $q : $q->where(fn ($w) => $w->whereIn('branch_id', $b)->orWhereNull('branch_id'));
    }

    /** @return array<string, string> */
    public static function people(string $permission): array
    {
        $authz = app(Authorizer::class);

        return User::where('is_active', true)->orderBy('name')->get()->filter(fn (User $u) => $authz->allowsAnywhere($u, $permission))->pluck('name', 'id')->all();
    }
}
