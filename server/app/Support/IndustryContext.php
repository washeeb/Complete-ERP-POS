<?php

namespace App\Support;

use App\Domain\Support\DomainException;
use App\Models\Branch;
use App\Models\Industry;
use App\Models\RoleAssignment;
use App\Models\Till;
use App\Models\User;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * Industries whose records the current request sees (owner 2026-11-09: every industry independent). A till sees its
     * own branch's industry; a person sees the chosen industry, or all of their own; null means no filter (company-wide
     * people under "All industries", head-office tills, the scheduler and other background work).
     *
     * @return list<string>|null
     */
    public static function visibleIds(): ?array
    {
        $device = request()->attributes->get('device');
        if ($device) {
            $key = 'erp.industry.device';
            if (! request()->attributes->has($key)) {
                $industry = Branch::whereKey($device->branch_id)->value('industry_id');
                request()->attributes->set($key, $industry ? [$industry] : null);
            }

            return request()->attributes->get($key);
        }
        $user = auth()->user();
        if (! $user instanceof User) {
            return null;
        }
        if ($id = self::selected()) {
            return [$id];
        }
        if (self::companyWide($user)) {
            return null;
        }
        $own = self::remembered($user)->keys()->all();

        return $own ?: null; // head-office staff with no industry: company-level, like company-wide people
    }

    /**
     * Put a record in the industry a form or file chose: only one this person may choose, "Shared" only where sharing is
     * allowed and the kind of record may be shared. Nothing chosen keeps the record's industry (a new one gets the
     * default when it is created).
     */
    public static function assign(Model $record, array $data, bool $shareable = true, ?User $user = null): void
    {
        $user ??= auth()->user(); // whoever does the work: also imports and other background work
        if (! array_key_exists('industry_id', $data) || ($data['industry_id'] ?? null) === null || $data['industry_id'] === '') {
            return;
        }
        $value = (string) $data['industry_id'];
        $current = $record->exists ? ($record->getOriginal('industry_id') ?? self::SHARED) : null;
        if ($value === $current) {
            return;
        }
        if ($value === self::SHARED && ! $shareable) {
            throw new DomainException('Choose the industry it belongs to.');
        }
        if (! array_key_exists($value, self::choices($user))) {
            throw new DomainException('Choose one of your own industries.');
        }
        $record->industry_id = $value === self::SHARED ? null : $value;
    }

    /** The industry a new record gets when the form does not say: the chosen one, or the person's (or till's) only one. */
    public static function defaultId(): ?string
    {
        $ids = self::visibleIds();

        return $ids !== null && count($ids) === 1 ? $ids[0] : null;
    }

    /**
     * Users this person sees in an industry: those whose grants reach its branches or the industry itself. People with
     * company-wide access show only under "All industries", and only to company-wide people (owner decision 2026-11-09).
     */
    public static function userScope(Builder $query): Builder
    {
        $viewer = auth()->user();
        if (! $viewer instanceof User) {
            return $query;
        }
        $ids = self::visibleIds();
        $companyWide = fn ($q) => $q->select('user_id')->from('role_assignments')->where('scope_type', ScopeType::Tenant->value)
            ->whereNull('revoked_at')->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        if ($ids === null) {
            return self::companyWide($viewer) ? $query : $query->whereNotIn('id', $companyWide);
        }
        $branches = Branch::whereIn('industry_id', $ids)->pluck('id');
        $warehouses = Warehouse::whereIn('branch_id', $branches)->pluck('id');
        $tills = Till::whereIn('branch_id', $branches)->pluck('id');
        $entities = Industry::whereIn('id', $ids)->whereNotNull('legal_entity_id')->pluck('legal_entity_id');

        return $query->whereNotIn('id', $companyWide)->whereIn('id', fn ($q) => $q->select('user_id')->from('role_assignments')
            ->whereNull('revoked_at')->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($w) => $w->where(fn ($x) => $x->where('scope_type', ScopeType::Industry->value)->whereIn('scope_id', $ids))
                ->orWhere(fn ($x) => $x->where('scope_type', ScopeType::Branch->value)->whereIn('scope_id', $branches))
                ->orWhere(fn ($x) => $x->where('scope_type', ScopeType::Warehouse->value)->whereIn('scope_id', $warehouses))
                ->orWhere(fn ($x) => $x->where('scope_type', ScopeType::Till->value)->whereIn('scope_id', $tills))
                ->orWhere(fn ($x) => $x->where('scope_type', ScopeType::LegalEntity->value)->whereIn('scope_id', $entities))));
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
