<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Industry;
use App\Models\RoleAssignment;
use App\Models\Till;
use App\Models\User;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The industry chosen in the back office's switcher ("All industries", "Pharmacy", "Grocery", …), kept in the
 * session. Every branch-filtered list goes through Erp::branchIds(), which narrows to the chosen industry's branches,
 * so stock, prices, purchasing, sales and reports all follow the switch. Catalogue screens filter on it directly.
 * Someone with one industry and no company-wide grant has no choice to make: they are always in it (owner decision
 * 2026-11-07). "All industries" is for company-wide people and those who cover several industries.
 */
final class IndustryContext
{
    /** Form and file value for a product every industry sells, chosen on purpose. */
    public const SHARED = 'shared';

    private const KEY = 'erp.industry';

    /** The chosen industry's id, or the person's only one; null for all industries. */
    public static function selected(): ?string
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return null;
        }
        $options = self::remembered($user);
        $id = session(self::KEY);
        if ($id && $options->has($id)) {
            return $id;
        }

        return $options->count() === 1 && ! self::companyWide($user) ? $options->keys()->first() : null;
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
        $all = Industry::inUse()->orderBy('sort')->orderBy('name')->pluck('name', 'id'); // purchased and installed only
        if ($all->isEmpty()) {
            return $all;
        }
        $grants = RoleAssignment::where('user_id', $user->id)->active()->get(['scope_type', 'scope_id']);
        if ($grants->contains(fn ($g) => $g->scope_type === ScopeType::Tenant)) {
            return $all;
        }
        $scoped = fn (ScopeType $type) => $grants->where('scope_type', $type)->pluck('scope_id');
        $entities = $scoped(ScopeType::LegalEntity);
        $branches = $scoped(ScopeType::Branch)
            ->merge(Warehouse::whereIn('id', $scoped(ScopeType::Warehouse))->pluck('branch_id'))
            ->merge(Till::whereIn('id', $scoped(ScopeType::Till))->pluck('branch_id'));
        $ids = $scoped(ScopeType::Industry)
            ->merge(Branch::whereIn('id', $branches)->pluck('industry_id'))
            ->merge(Branch::whereIn('legal_entity_id', $entities)->pluck('industry_id'))
            ->merge(Industry::whereIn('legal_entity_id', $entities)->pluck('id'))
            ->filter()->unique();

        return $all->only($ids->all());
    }

    /** Holds a grant over the whole company, which is what makes every industry and head office visible. */
    public static function companyWide(User $user): bool
    {
        return RoleAssignment::where('user_id', $user->id)->active()->where('scope_type', ScopeType::Tenant)->exists();
    }

    public static function sharingAllowed(User $user): bool
    {
        return self::companyWide($user) && app(Settings::class)->get('catalog.shared_products', $user->tenant_id) !== 'never';
    }

    /**
     * What this person may put a product in: company-wide people and head-office staff with no industry may choose any
     * licensed industry, everyone else only their own; "Shared" is a separate, deliberate choice.
     *
     * @return array<string, string>
     */
    public static function choices(?User $user): array
    {
        if (! $user) {
            return [];
        }
        $own = self::options($user);
        $choices = (self::companyWide($user) || $own->isEmpty() ? Industry::licensed()->orderBy('sort')->orderBy('name')->pluck('name', 'id') : $own)->all();

        return $choices + (self::sharingAllowed($user) ? [self::SHARED => 'Shared by all industries'] : []);
    }

    /** Products this person works with: the chosen industry and shared items, or all of their own industries. */
    public static function productScope(Builder $query): Builder
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return $query;
        }
        $ids = ($id = self::selected()) ? [$id] : (self::companyWide($user) ? [] : self::remembered($user)->keys()->all());

        return $query->when($ids, fn ($q) => $q->where(fn ($w) => $w->whereNull('industry_id')->orWhereIn('industry_id', $ids)));
    }

    /** selected() runs many times on a page. */
    private static function remembered(User $user): Collection
    {
        $key = 'erp.industry.options.'.$user->id;
        if (! request()->attributes->has($key)) {
            request()->attributes->set($key, self::options($user));
        }

        return request()->attributes->get($key);
    }
}
