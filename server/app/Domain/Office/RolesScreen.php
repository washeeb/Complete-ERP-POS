<?php

namespace App\Domain\Office;

use App\Domain\Access\AccessRequests;
use App\Domain\Org\ArchiveService;
use App\Filament\Resources\AccessRequests\AccessRequestResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\AccessRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Security\SecurityLevel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Roles and access requests in the new back office (settings and admin, part 2 of the rewrite), as the current screens:
 * the roles (search, in use or archived; level, permissions, technical, seeded), one role with its permissions grouped
 * by area, Add and Change, Archive and Restore; and the access requests IT Admin made, with Approve, Turn down and
 * Withdraw, through AccessService, AccessRequests and ArchiveService (RolesController). Who may do what is unchanged.
 */
final class RolesScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const SORTS = ['name', 'security_level'];

    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return RoleResource::canViewAny();
    }

    public static function canManage(): bool
    {
        return RoleResource::canCreate();
    }

    public static function canRequests(): bool
    {
        return AccessRequestResource::canViewAny();
    }

    public function filtered(array $p): Builder
    {
        $q = RoleResource::getEloquentQuery()->withCount('permissions');
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where('roles.name', 'ilike', "%{$search}%");
        }
        match ($p['show'] ?? '') {
            'archived' => $q->whereNotNull('roles.archived_at'),
            'both' => null,
            default => $q->whereNull('roles.archived_at'),
        };

        return $q;
    }

    public function rowOf(Role $r): array
    {
        return ['id' => $r->id, 'name' => $r->name, 'description' => $r->description, 'level' => (int) $r->security_level, 'permissions' => (int) ($r->permissions_count ?? $r->permissions()->count()),
            'technical' => (bool) $r->is_technical, 'seeded' => (bool) $r->is_system, 'archived' => $r->archived_at !== null];
    }

    /** @param  array<string, mixed>  $p  q, show, sort, dir, page, per */
    public function list(array $p): array
    {
        $sort = in_array($p['sort'] ?? null, self::SORTS, true) ? $p['sort'] : 'security_level';
        $dir = ($p['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));

        return [
            'total' => (clone $this->filtered($p))->count(), 'page' => $page, 'per' => $per, 'sort' => $sort, 'dir' => $dir,
            'can_manage' => self::canManage(), 'can_requests' => self::canRequests(), 'waiting' => self::canRequests() ? AccessRequest::query()->waiting()->count() : 0,
            'rows' => $this->filtered($p)->orderBy("roles.{$sort}", $dir)->orderBy('roles.name')->forPage($page, $per)->get()->map(fn (Role $r) => $this->rowOf($r))->all(),
        ];
    }

    /** The form: levels up to your own (never the SyncLytics level), and every permission grouped by area. */
    public function form(): array
    {
        $levels = SecurityLevel::options(min((int) $this->user->security_level, 6));

        return [
            'levels' => array_map(fn ($k, $v) => ['id' => (string) $k, 'label' => (string) $v], array_keys($levels), $levels),
            'areas' => Permission::orderBy('module')->orderBy('key')->get()->groupBy('module')->map(fn ($ps, $module) => ['area' => (string) $module,
                'permissions' => $ps->map(fn (Permission $p) => ['key' => $p->key, 'label' => $p->label, 'min_level' => (int) $p->min_level, 'technical' => (bool) $p->is_technical])->values()->all()])
                ->values()->all(),
        ];
    }

    public function find(string $id): ?Role
    {
        return RoleResource::getEloquentQuery()->find($id);
    }

    public function show(Role $r): array
    {
        $archive = app(ArchiveService::class);
        $allowed = $archive->allowed($this->user, $r);

        return $this->rowOf($r) + [
            'key' => $r->key, 'keys' => $r->permissionKeys(), 'holders' => RoleAssignment::where('role_id', $r->id)->active()->count(),
            'can_edit' => RoleResource::canEdit($r), 'can_archive' => ! $r->archived_at && $allowed, 'archive_blockers' => ! $r->archived_at && $allowed ? $archive->blockers($r) : [],
            'can_restore' => (bool) $r->archived_at && $allowed,
        ];
    }

    /** Access requests, newest first; status filter as on the current screen ("expired" includes ones that lapsed unanswered). */
    public function requests(array $p): array
    {
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $q = AccessRequest::query();
        $status = (string) ($p['status'] ?? '');
        if ($status === 'pending') {
            $q->waiting();
        } elseif ($status === 'expired') {
            $q->where(fn ($w) => $w->where('status', 'expired')->orWhere(fn ($x) => $x->where('status', 'pending')->where('expires_at', '<=', now())));
        } elseif (array_key_exists($status, AccessRequest::STATUSES)) {
            $q->where('status', $status);
        }
        $me = $this->user;

        return [
            'total' => (clone $q)->count(), 'page' => $page, 'per' => $per, 'statuses' => AccessRequest::STATUSES,
            'rows' => $q->orderByDesc('created_at')->orderByDesc('id')->forPage($page, $per)->get()->map(fn (AccessRequest $r) => [
                'id' => $r->id, 'status' => $r->status === 'pending' && ! $r->isWaiting() ? 'expired' : $r->status,
                'status_label' => $r->status === 'pending' && ! $r->isWaiting() ? AccessRequest::STATUSES['expired'] : (AccessRequest::STATUSES[$r->status] ?? $r->status),
                'kind' => AccessRequest::KINDS[$r->kind] ?? $r->kind, 'summary' => $r->summary, 'by' => $r->requested_by_name, 'asked' => $r->created_at?->toIso8601String(),
                'lapses' => $r->expires_at?->toIso8601String(), 'decided_by' => $r->decided_by_name, 'note' => $r->decision_note,
                'can_decide' => $r->isWaiting() && AccessRequests::mayDecide($me, $r), 'can_withdraw' => $r->isWaiting() && $r->requested_by === $me->id,
            ])->all(),
        ];
    }
}
