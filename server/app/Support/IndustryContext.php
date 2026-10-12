<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Industry;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Security\ScopeType;
use Illuminate\Support\Collection;

/**
 * The industry chosen in the back office's switcher ("All industries", "Pharmacy", "Grocery", …), kept in the
 * session. Every branch-filtered list goes through Erp::branchIds(), which narrows to the chosen industry's branches,
 * so stock, prices, purchasing, sales and reports all follow the switch. Catalogue screens filter on it directly.
 */
final class IndustryContext
{
    private const KEY = 'erp.industry';

    /** The chosen industry's id, or null for all industries (and when the choice is no longer valid). */
    public static function selected(): ?string
    {
        $id = session(self::KEY);
        if (! $id) {
            return null;
        }
        $user = auth()->user();

        return $user instanceof User && self::options($user)->has($id) ? $id : null;
    }

    public static function set(?string $id): void
    {
        session([self::KEY => $id ?: null]);
    }

    public static function industry(): ?Industry
    {
        return ($id = self::selected()) ? Industry::find($id) : null;
    }

    /** Branch ids of the chosen industry; null when no industry is chosen. */
    public static function branchIds(): ?array
    {
        return ($id = self::selected()) ? Branch::where('industry_id', $id)->pluck('id')->all() : null;
    }

    /**
     * Industries this person can switch to: all of them with a company-wide grant; otherwise those of the branches
     * and industries their grants cover.
     *
     * @return Collection<string, string> id => name
     */
    public static function options(User $user): Collection
    {
        $all = Industry::where('is_active', true)->orderBy('sort')->orderBy('name')->pluck('name', 'id');
        if ($all->isEmpty()) {
            return $all;
        }
        $grants = RoleAssignment::where('user_id', $user->id)->active()->get(['scope_type', 'scope_id']);
        if ($grants->contains(fn ($g) => in_array($g->scope_type, [ScopeType::Tenant, ScopeType::LegalEntity], true))) {
            return $all;
        }
        $ids = $grants->where('scope_type', ScopeType::Industry)->pluck('scope_id')
            ->merge(Branch::whereIn('id', $grants->where('scope_type', ScopeType::Branch)->pluck('scope_id'))->pluck('industry_id'))
            ->filter()->unique();

        return $all->only($ids->all());
    }
}
