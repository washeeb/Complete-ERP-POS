<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Security\ScopeType;
use Illuminate\Support\Collection;

/**
 * The branch chosen at the top of the back office (owner request 2026-11-26, free): "All my branches" or one branch.
 * Like the industry switch, it is kept in the session and every branch-filtered list goes through Erp::branchIds(),
 * so lists, reports, the Business Portal and To-do show only that branch, and forms offer (and so fill in) only its
 * stores. The branches offered are those the person's grants cover, within the industry chosen beside it; a choice
 * that no longer fits (another industry chosen, access taken away) is simply ignored.
 */
final class BranchContext
{
    private const KEY = 'erp.branch';

    /** The chosen branch's id; null for all the person's branches. */
    public static function selected(): ?string
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return null;
        }
        // This session's choice; at the start of a session, the one saved for the person (WorkScope).
        $id = session()->exists(self::KEY) ? session(self::KEY) : ($user->ui_prefs['scope']['branch'] ?? null);

        return is_string($id) && self::remembered($user)->has($id) ? $id : null;
    }

    public static function set(?string $id): void
    {
        session([self::KEY => $id ?: null]);
    }

    /** The chosen branch only, or null (no narrowing by branch). */
    public static function branchIds(): ?array
    {
        return ($id = self::selected()) ? [$id] : null;
    }

    /**
     * Branches this person can switch to: those their grants cover (any role), active, within the chosen industry.
     *
     * @return Collection<string, string> id => "CODE · Name"
     */
    public static function options(User $user): Collection
    {
        return self::optionsIn($user, IndustryContext::selected());
    }

    /**
     * The same within one industry; null = every branch the person covers (company-level ones included).
     *
     * @return Collection<string, string> id => "CODE · Name"
     */
    public static function optionsIn(User $user, ?string $industry): Collection
    {
        $grants = RoleAssignment::where('user_id', $user->id)->active()->get(['scope_type', 'scope_id']);
        $q = Branch::query()->where('is_active', true)->whereNull('archived_at');
        if (! $grants->contains(fn ($g) => $g->scope_type === ScopeType::Tenant)) {
            $of = fn (ScopeType $t) => $grants->where('scope_type', $t)->pluck('scope_id')->all();
            $q->where(fn ($w) => $w->whereIn('id', $of(ScopeType::Branch) ?: ['00000000-0000-0000-0000-000000000000'])
                ->orWhereIn('industry_id', $of(ScopeType::Industry))
                ->orWhereIn('legal_entity_id', $of(ScopeType::LegalEntity)));
        }
        if ($industry !== null && $industry !== '') {
            $q->where('industry_id', $industry);
        }

        return $q->orderBy('code')->get(['id', 'code', 'name'])->mapWithKeys(fn (Branch $b) => [(string) $b->id => "{$b->code} · {$b->name}"]);
    }

    /** Options once per request: every list asks. */
    private static function remembered(User $user): Collection
    {
        $key = 'erp.branch.options.'.$user->id.'.'.IndustryContext::selected();
        if (! request()->attributes->has($key)) {
            request()->attributes->set($key, self::options($user));
        }

        return request()->attributes->get($key);
    }
}
