<?php

namespace App\Domain\Office;

use App\Domain\Org\ArchiveService;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\Erp;
use App\Filament\Support\LocalAvatars;
use App\Models\Branch;
use App\Models\Industry;
use App\Models\LegalEntity;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Till;
use App\Models\User;
use App\Models\Warehouse;
use App\Security\RoleCatalog;
use App\Security\ScopeType;
use App\Security\SecurityLevel;
use App\Support\Money;
use App\Support\People;
use App\Support\Plain;
use App\Support\StaffCard;
use Illuminate\Database\Eloquent\Builder;

/**
 * Users in the new back office (settings and admin, part 1 of the rewrite), as the current screens: the list (search,
 * in use or archived; level, two-step sign-in, how they sign in, PIN), one user with their details and their access
 * (roles and where they apply), Add, Change, Set till PIN, Staff card, Card lost, Reset two-step sign-in, Archive and
 * Restore, Grant access and Revoke, through AccessService, StaffCard and ArchiveService (UsersController).
 * Who may do what is unchanged: the list follows UserResource (the company, and the industry chosen at the top).
 */
final class UsersScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const SORTS = ['name', 'security_level', 'last_login_at'];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return UserResource::canViewAny();
    }

    public static function canManage(): bool
    {
        return UserResource::canCreate();
    }

    public static function canGrant(): bool
    {
        return Erp::can('admin.roles.manage');
    }

    private function currency(): string
    {
        return $this->user->tenant?->currency_code ?? 'GHS';
    }

    public function filtered(array $p): Builder
    {
        $q = UserResource::getEloquentQuery();
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn (Builder $w) => $w->where('users.name', 'ilike', "%{$search}%")->orWhere('users.username', 'ilike', "%{$search}%")
                ->orWhere('users.email', 'ilike', "%{$search}%"));
        }
        match ($p['show'] ?? '') {
            'archived' => $q->whereNotNull('users.archived_at'),
            'both' => null,
            default => $q->whereNull('users.archived_at'),
        };

        return $q;
    }

    public static function signsInWith(User $u): string
    {
        return $u->isSupport() ? 'Authenticator app (always)' : match ($u->signin_method) {
            'app' => 'App', 'code' => 'Code', 'password' => 'Password only', default => $u->requiresMfa() ? 'App (company rule)' : 'Password (company rule)',
        };
    }

    public function rowOf(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name, 'username' => $u->username, 'email' => $u->email, 'photo' => $u->getFilamentAvatarUrl() ?? LocalAvatars::svgDataUri($u->name),
            'support' => $u->isSupport(), 'level' => (int) $u->security_level, 'mfa' => $u->app_authentication_secret !== null, 'signs_in' => self::signsInWith($u),
            'pin' => $u->pin_hash !== null, 'last_till' => $u->last_login_at?->toIso8601String(), 'archived' => $u->archived_at !== null, 'active' => (bool) $u->is_active];
    }

    /** @param  array<string, mixed>  $p  q, show, sort, dir, page, per */
    public function list(array $p): array
    {
        $sort = in_array($p['sort'] ?? null, self::SORTS, true) ? $p['sort'] : 'name';
        $dir = ($p['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));

        return [
            'total' => (clone $this->filtered($p))->count(), 'page' => $page, 'per' => $per, 'sort' => $sort, 'dir' => $dir,
            'rows' => $this->filtered($p)->orderBy("users.{$sort}", $dir)->orderBy('users.id', $dir)->forPage($page, $per)->get()->map(fn (User $u) => $this->rowOf($u))->all(),
        ];
    }

    /** What the forms choose from: levels up to your own (never the platform level), sign-in methods, and for Grant access the roles and places. */
    public function options(): array
    {
        $grant = self::canGrant();
        $pairs = fn (array $a) => array_map(fn ($k, $v) => ['id' => (string) $k, 'label' => (string) $v], array_keys($a), $a);
        $currency = $this->currency();

        return [
            'per' => self::PER_PAGE, 'can_manage' => self::canManage(), 'can_grant' => $grant,
            'levels' => $pairs(SecurityLevel::options(min((int) $this->user->security_level, 6))), 'signin_methods' => $pairs(User::SIGNIN_METHODS),
            'password_only_max' => User::PASSWORD_ONLY_MAX_LEVEL, 'card_layouts' => $pairs(StaffCard::LAYOUTS), 'currency' => $currency, 'exponent' => Money::exponent($currency),
            'roles' => $grant ? Role::whereNull('archived_at')->whereNotIn('key', RoleCatalog::hiddenKeys())->orderBy('security_level')->orderBy('name')->get()
                ->map(fn (Role $r) => ['id' => $r->id, 'label' => "{$r->name} (L{$r->security_level})"])->all() : [],
            'scopes' => $grant ? array_map(fn (ScopeType $s) => ['id' => $s->value, 'label' => $s->label()], ScopeType::cases()) : [],
            'places' => $grant ? [
                'legal_entity' => $pairs(LegalEntity::choices()),
                'industry' => $pairs(Industry::licensed()->orderBy('sort')->pluck('name', 'id')->all()),
                'branch' => $pairs(Branch::choices(null, 'both')),
                'warehouse' => $pairs(Warehouse::orderBy('code')->get()->mapWithKeys(fn ($w) => [$w->id => "{$w->code} · {$w->name}"])->all()),
                'till' => $pairs(Till::with('branch')->get()->mapWithKeys(fn ($t) => [$t->id => "{$t->branch->code}-{$t->code} · {$t->name}"])->all()),
            ] : [],
        ];
    }

    public function find(string $id): ?User
    {
        return UserResource::getEloquentQuery()->find($id);
    }

    public static function canEdit(User $u): bool
    {
        return UserResource::canEdit($u);
    }

    private function grantOf(RoleAssignment $g): array
    {
        $status = match (true) {
            $g->revoked_at !== null => 'Revoked', (bool) $g->expires_at?->isPast() => 'Expired', $g->expires_at !== null => 'Temporary', default => 'Active',
        };

        return ['id' => $g->id, 'role' => $g->role?->name, 'applies_to' => $g->scopeLabel(), 'limits' => Plain::limits($g->limits, $this->currency()), 'status' => $status,
            'current' => in_array($status, ['Active', 'Temporary'], true), 'expires' => $g->expires_at?->toIso8601String(), 'by' => $g->granted_by_name, 'reason' => $g->reason,
            'can_revoke' => $g->revoked_at === null && self::canGrant()];
    }

    /** One user: details, their access (newest first), and what you may do to them. */
    public function show(User $u): array
    {
        $edit = self::canEdit($u);
        $archive = app(ArchiveService::class);
        $allowed = $archive->allowed($this->user, $u);

        return $this->rowOf($u) + [
            'phone' => $u->phone, 'signin_method' => $u->signin_method ?: 'default', 'card' => $u->badge_hash !== null, 'me' => $u->id === $this->user->id,
            'can_edit' => $edit, 'can_reset_mfa' => $edit && $u->app_authentication_secret !== null && $u->id !== $this->user->id,
            'can_archive' => ! $u->archived_at && $allowed, 'archive_blockers' => ! $u->archived_at && $allowed ? $archive->blockers($u) : [], 'can_restore' => (bool) $u->archived_at && $allowed,
            'grants' => $u->roleAssignments()->with('role')->latest('created_at')->get()->map(fn (RoleAssignment $g) => $this->grantOf($g))->all(),
            'history' => People::history($u->id),
        ];
    }
}
