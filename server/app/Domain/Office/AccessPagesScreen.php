<?php

namespace App\Domain\Office;

use App\Domain\Access\AccessReview;
use App\Domain\Access\AccessService;
use App\Filament\Pages\AccessReviewPage;
use App\Filament\Pages\CompanyWideAccess;
use App\Filament\Pages\ItActivity;
use App\Filament\Pages\StaffCards;
use App\Filament\Resources\Users\UserResource;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Industry;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Security\ScopeType;
use App\Security\SecurityLevel;
use App\Support\ClientInfo;
use App\Support\Plain;
use App\Support\StaffCard;
use Illuminate\Database\Eloquent\Builder;

/**
 * The other People & access pages in the new back office (settings and admin, part 3 of the rewrite), as the current
 * screens: Company-wide access (who sees the whole company, and Give a branch), Who can do what (risky combinations,
 * notes, everyone's access; CSV), IT and support activity (from the audit log, read only) and Staff cards (print new
 * cards, stop lost ones), through AccessService, AccessReview and StaffCard (AccessPagesController).
 */
final class AccessPagesScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public function __construct(private readonly User $user) {}

    private static function page(int $per, int $page, int $total): array
    {
        return ['page' => $page, 'per' => $per, 'total' => $total];
    }

    private static function paging(array $p): array
    {
        return [in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25, max(1, (int) ($p['page'] ?? 1))];
    }

    // Company-wide access ------------------------------------------------------------------------------------------

    /** @return list<string> roles that may be company-wide (Settings → Security) */
    private static function allowedRoles(): array
    {
        return Role::all()->filter(fn (Role $r) => AccessService::mayBeCompanyWide($r))->modelKeys();
    }

    public static function companyWideQuery(): Builder
    {
        return RoleAssignment::query()->active()->whereIn('scope_type', [ScopeType::Tenant, ScopeType::LegalEntity]);
    }

    /** @param  array<string, mixed>  $p  show (needs | allowed | all), page, per */
    public function companyWide(array $p): array
    {
        [$per, $page] = self::paging($p);
        $allowed = self::allowedRoles();
        $q = self::companyWideQuery()->with('user', 'role');
        match ($p['show'] ?? '') {
            'allowed' => $q->whereIn('role_id', $allowed),
            'all' => null,
            default => $q->whereNotIn('role_id', $allowed),
        };

        return self::page($per, $page, (clone $q)->count()) + [
            'needs' => self::companyWideQuery()->whereNotIn('role_id', $allowed)->count(),
            'branches' => array_map(fn ($k, $v) => ['id' => (string) $k, 'label' => (string) $v], array_keys($b = Branch::choices(null, 'both')), $b),
            'industries' => Industry::licensed()->orderBy('sort')->get()->map(fn ($i) => ['id' => $i->id, 'label' => $i->name])->all(),
            'rows' => $q->orderBy('created_at')->forPage($page, $per)->get()->map(fn (RoleAssignment $a) => [
                'id' => $a->id, 'person' => $a->user?->name, 'user_id' => $a->user_id, 'role' => $a->role?->name, 'applies_to' => $a->scope_type->label(),
                'allowed' => $a->role && AccessService::mayBeCompanyWide($a->role),
            ])->all(),
        ];
    }

    public static function canCompanyWide(): bool
    {
        return CompanyWideAccess::canAccess();
    }

    // Who can do what ----------------------------------------------------------------------------------------------

    public static function canReview(): bool
    {
        return AccessReviewPage::canAccess();
    }

    public function review(): array
    {
        $r = app(AccessReview::class)->build($this->user->tenant_id);
        $open = UserResource::canViewAny();

        return [
            'counts' => $r['counts'], 'can_open' => $open,
            'risks' => array_map(fn ($x) => ['severity' => $x['severity'], 'title' => $x['title'], 'why' => $x['why'], 'fix' => $x['fix'], 'who' => $x['who']], $r['risks']),
            'notes' => $r['notes'],
            'people' => array_map(fn ($x) => ['id' => $x['user']->id, 'name' => $x['user']->name, 'username' => $x['user']->username, 'active' => (bool) $x['user']->is_active,
                'owner' => $x['owner'], 'roles' => $x['roles'],
                'risks' => array_map(fn ($k) => ['severity' => AccessReview::RULES[$k][0], 'title' => AccessReview::RULES[$k][2]], $x['risks'])], $r['people']),
        ];
    }

    /** @return list<array<string, string>> */
    public function reviewRows(): array
    {
        $svc = app(AccessReview::class);

        return $svc->rows($svc->build($this->user->tenant_id))->all();
    }

    // IT and support activity --------------------------------------------------------------------------------------

    public static function canActivity(): bool
    {
        return ItActivity::canAccess();
    }

    /** @param  array<string, mixed>  $p  who (tech | support), when (7 | 30 | 90 | all), q, page, per */
    public function activity(array $p): array
    {
        [$per, $page] = self::paging($p);
        $people = ItActivity::technicalPeople();
        $support = User::where('tenant_id', $this->user->tenant_id)->where('security_level', '>=', SecurityLevel::Platform->value)->pluck('id')->all();
        $q = ItActivity::query();
        match ($p['who'] ?? '') {
            'tech' => $q->whereIn('actor_id', $people ?: ['00000000-0000-0000-0000-000000000000']),
            'support' => $q->whereIn('actor_id', $support ?: ['00000000-0000-0000-0000-000000000000']),
            default => null,
        };
        $when = (string) ($p['when'] ?? '30');
        if (is_numeric($when)) {
            $q->where('occurred_at', '>=', now()->subDays((int) $when));
        }
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where('actor_name', 'ilike', "%{$search}%");
        }

        return self::page($per, $page, (clone $q)->count()) + [
            'rows' => $q->orderByDesc('seq')->forPage($page, $per)->get()->map(fn (AuditEvent $e) => [
                'id' => $e->id, 'when' => $e->occurred_at?->toIso8601String(), 'who' => $e->actor_name,
                'who_kind' => in_array($e->actor_id, $support, true) ? 'SyncLytics support' : (in_array($e->actor_id, $people, true) ? 'IT' : ($e->actor_id ? null : Plain::source($e->source))),
                'what' => Plain::action($e->action), 'record' => Plain::record($e->subject_type),
                'details' => trim(collect($e->changes)->take(4)->map(fn ($pair, $key) => Plain::field((string) $key).': '
                    .(is_array($pair) && count($pair) === 2 && array_is_list($pair) ? Plain::value((string) $key, $pair[0]).' → '.Plain::value((string) $key, $pair[1]) : Plain::value((string) $key, $pair)))
                    ->implode(' · ').($e->reason ? ' · '.$e->reason : ''), ' ·') ?: null,
                'computer' => ClientInfo::summary($e->client, $e->ip),
                'changes' => collect($e->changes)->map(fn ($pair, $key) => ['field' => Plain::field((string) $key),
                    'value' => is_array($pair) && count($pair) === 2 && array_is_list($pair) ? Plain::value((string) $key, $pair[0]).' → '.Plain::value((string) $key, $pair[1]) : Plain::value((string) $key, $pair)])
                    ->values()->all(),
                'reason' => $e->reason,
            ])->all(),
        ];
    }

    // Staff cards --------------------------------------------------------------------------------------------------

    public static function canCards(): bool
    {
        return StaffCards::canAccess();
    }

    public static function cardsQuery(): Builder
    {
        return User::query()->where('tenant_id', auth()->user()?->tenant_id)->where('is_active', true)->where('security_level', '<', SecurityLevel::Platform->value);
    }

    /** @param  array<string, mixed>  $p  q, card (none | has), page, per */
    public function cards(array $p): array
    {
        [$per, $page] = self::paging($p);
        $q = self::cardsQuery()->with(['roleAssignments' => fn ($r) => $r->active()->with('role')]);
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn ($w) => $w->where('name', 'ilike', "%{$search}%")->orWhere('username', 'ilike', "%{$search}%"));
        }
        match ($p['card'] ?? '') {
            'none' => $q->whereNull('badge_hash'),
            'has' => $q->whereNotNull('badge_hash'),
            default => null,
        };

        return self::page($per, $page, (clone $q)->count()) + [
            'layouts' => array_map(fn ($k, $v) => ['id' => $k, 'label' => $v], array_keys(StaffCard::LAYOUTS), StaffCard::LAYOUTS),
            'rows' => $q->orderBy('name')->orderBy('id')->forPage($page, $per)->get()->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'username' => $u->username, 'photo' => $u->getFilamentAvatarUrl(),
                'roles' => $u->roleAssignments->map(fn ($g) => ($g->role?->name ?? '?').' · '.$g->scopeLabel())->all(),
                'pin' => $u->pin_hash !== null, 'card' => $u->badge_hash !== null, 'card_on' => $u->badge_issued_at ? substr((string) $u->badge_issued_at, 0, 10) : null,
            ])->all(),
        ];
    }
}
