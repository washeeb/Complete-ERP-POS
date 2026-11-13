<?php

namespace App\Domain\Access;

use App\Domain\Support\Actor;
use App\Domain\Support\Recorder;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Security\Authorizer;
use App\Security\SecurityLevel;
use Illuminate\Support\Facades\DB;

/**
 * The Owner runs the business; the technical side (users, roles, devices, system settings, sync, integrations) is done
 * by IT Admin and the SyncLytics support account (Superadmin). Owner decision 2026-11-13: "this is to make sure the
 * system stays safe even when anyone gets access to owners PC or credentials."
 *
 * The Owner role keeps every business permission and a read-only view of users and their access. Its technical
 * permissions come off only once someone else can do that work in the company (an active Superadmin or IT Admin), so a
 * company is never left with nobody able to add a user. Until then the owner keeps them and is asked to name one.
 */
final class OwnerSeparation
{
    /** Technical permissions the owner still holds: seeing who has which access (oversight, no changes). */
    public const OWNER_MAY_SEE = ['admin.users.view'];

    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz) {}

    /** Someone other than the owner can do the technical work: the support account, or an active IT Admin. */
    public static function hasTechnicalAdmin(string $tenantId): bool
    {
        return RoleAssignment::withoutGlobalScopes()->where('role_assignments.tenant_id', $tenantId)->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')->whereIn('roles.key', ['superadmin', 'it_admin'])
            ->join('users', 'users.id', '=', 'role_assignments.user_id')->where('users.is_active', true)
            ->exists();
    }

    /** @return list<string> technical permission keys the Owner role may not hold */
    public static function forbiddenForOwner(): array
    {
        return Permission::where('is_technical', true)->whereNotIn('key', self::OWNER_MAY_SEE)->pluck('key')->all();
    }

    /** Take the technical permissions off the Owner role, once someone else can do that work. Returns how many came off. */
    public function apply(string $tenantId, string $why): int
    {
        if (! self::hasTechnicalAdmin($tenantId)) {
            return 0;
        }
        $owner = Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('key', 'owner')->first();
        if (! $owner) {
            return 0;
        }
        $remove = array_values(array_intersect($owner->permissionKeys(), self::forbiddenForOwner()));
        if ($remove === []) {
            return 0;
        }
        $actor = Actor::system($tenantId, 'Owner and technical access kept apart', 'system');
        $this->recorder->transaction(function () use ($actor, $owner, $remove, $why) {
            $owner->permissions()->detach($remove);
            $this->recorder->audit->record($actor, 'role.permissions_changed', $owner, ['added' => [null, []], 'removed' => [null, $remove]], $why);
            $this->recorder->outbox->publish($actor, $owner, 'role.permissions_changed', ['permissions' => $owner->permissionKeys()]);
        });
        $holders = RoleAssignment::withoutGlobalScopes()->where('role_id', $owner->id)->pluck('user_id');
        foreach (User::whereIn('id', $holders)->get() as $u) {
            $this->authz->forget($u);
        }

        return count($remove);
    }

    /** Owner still holds technical access because nobody else can do that work yet (shown to the owner as a banner). */
    public static function ownerStillTechnical(string $tenantId): bool
    {
        $owner = Role::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('key', 'owner')->first();

        return $owner && DB::table('permission_role')->where('role_id', $owner->id)->whereIn('permission_key', self::forbiddenForOwner())->exists();
    }

    /** The owner's own account never also holds a technical role (stolen owner details must not reach the technical side). */
    public static function isOwnerAccount(User $user): bool
    {
        return $user->security_level < SecurityLevel::Platform->value && RoleAssignment::withoutGlobalScopes()->where('user_id', $user->id)->whereNull('revoked_at')
            ->whereHas('role', fn ($q) => $q->withoutGlobalScopes()->where('key', 'owner'))->exists();
    }
}
