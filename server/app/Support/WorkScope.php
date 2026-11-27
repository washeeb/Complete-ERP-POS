<?php

namespace App\Support;

use App\Domain\Access\AccessService;
use App\Domain\Support\Actor;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where the back office is looking: one industry (or all) and one branch (or all of that industry's), chosen in one
 * picker at the top (owner 2026-11-26/27). Shown to anyone whose access covers two or more branches; each person sees
 * only the industries and branches their access covers. The choice is kept for the session and saved with the
 * person's preferences, so they come back to the same place on any computer; a saved place they can no longer reach
 * is ignored (IndustryContext / BranchContext check it every time).
 */
final class WorkScope
{
    /** The last places chosen, offered first in the picker. */
    public const RECENT = 5;

    /**
     * The picker for this person, or null when there is nothing to choose (fewer than two branches).
     *
     * @return array{industry: string, branch: string, industries: list<array{id: string, label: string, branches: list<array{id: string, label: string}>}>, recent: list<array{industry: string, branch: string, label: string}>}|null
     */
    public static function picker(User $user): ?array
    {
        $all = BranchContext::optionsIn($user, null);
        if ($all->count() < 2) {
            return null;
        }
        $industries = IndustryContext::options($user);
        $list = [];
        if ($industries->count() !== 1 || IndustryContext::companyWide($user)) {
            $list[] = ['id' => '', 'label' => 'All industries', 'branches' => self::pairs($all)];
        }
        foreach ($industries as $id => $name) {
            $branches = self::pairs(BranchContext::optionsIn($user, (string) $id));
            if ($branches !== []) { // an industry with none of this person's branches is not offered
                $list[] = ['id' => (string) $id, 'label' => (string) $name, 'branches' => $branches];
            }
        }

        return ['industry' => (string) IndustryContext::selected(), 'branch' => (string) BranchContext::selected(),
            'industries' => $list, 'recent' => self::recent($user, $list)];
    }

    /** Whether this person may look at this place ('' = all). */
    public static function allows(array $picker, string $industry, string $branch): bool
    {
        $in = collect($picker['industries'])->firstWhere('id', $industry);

        return $in !== null && ($branch === '' || in_array($branch, array_column($in['branches'], 'id'), true));
    }

    /** Look at this place now, and remember it (and among the recent places) for the person. */
    public static function choose(User $user, string $industry, string $branch): void
    {
        IndustryContext::set($industry ?: null);
        BranchContext::set($branch ?: null);
        $prefs = UserPrefs::for($user);
        $recent = collect([['industry' => $industry, 'branch' => $branch], ...($prefs['scope']['recent'] ?? [])])
            ->unique(fn ($p) => $p['industry'].'|'.$p['branch'])->take(self::RECENT)->values()->all();
        $prefs['scope'] = ['industry' => $industry, 'branch' => $branch, 'recent' => $recent];
        DB::transaction(fn () => app(AccessService::class)->updateOwnProfile(Actor::user($user), $user, ['ui_prefs' => $prefs]));
    }

    /** The recent places still within reach, with their names. */
    private static function recent(User $user, array $list): array
    {
        $out = [];
        foreach ((array) (UserPrefs::for($user)['scope']['recent'] ?? []) as $p) {
            $in = collect($list)->firstWhere('id', $p['industry']);
            $b = $in ? collect($in['branches'])->firstWhere('id', $p['branch']) : null;
            if ($in && ($p['branch'] === '' || $b)) {
                $out[] = ['industry' => $p['industry'], 'branch' => $p['branch'], 'label' => $in['label'].' › '.($b['label'] ?? 'All branches')];
            }
        }

        return $out;
    }

    /** @param  Collection<string, string>  $options */
    private static function pairs(Collection $options): array
    {
        return $options->map(fn ($label, $id) => ['id' => (string) $id, 'label' => $label])->values()->all();
    }
}
