<?php

namespace App\Domain\Access;

use App\Domain\Licensing\LicenseManager;
use App\Domain\Messaging\Phone;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Industry;
use App\Models\LegalEntity;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\User;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Security\ScopeType;
use App\Security\SecurityLevel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/** Users, roles and grants. Every rule here exists to stop privilege escalation. */
final class AccessService
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz) {}

    public function createUser(Actor $actor, array $data): User
    {
        $this->require($actor, 'admin.users.manage');
        $level = (int) $data['security_level'];
        $this->assertLevelGrantable($actor, $level);
        app(LicenseManager::class)->assertWithin('users', User::where('is_active', true)->where('security_level', '<', SecurityLevel::Platform->value)->count(), 'active users');

        $user = new User;
        $user->forceFill([
            'tenant_id' => $actor->tenantId,
            'name' => $data['name'],
            'username' => strtolower($data['username']),
            'email' => strtolower($data['email']),
            'password' => $data['password'],
            'security_level' => $level,
            'is_active' => true,
            'created_by' => $actor->id,
            'phone' => filled($data['phone'] ?? null) ? trim($data['phone']) : null,
            'signin_method' => $data['signin_method'] ?? 'default',
        ]);
        $this->assertSignIn($user);
        $this->assertUnique($user);

        return $this->recorder->save($actor, $user, 'user.created');
    }

    public function updateUser(Actor $actor, User $user, array $data, ?string $reason = null): User
    {
        $this->require($actor, 'admin.users.manage');
        $this->assertSameTenant($actor, $user);
        if ($user->id !== $actor->id) {
            $this->assertLevelGrantable($actor, $user->security_level); // can't edit someone above you
        }
        if (array_key_exists('security_level', $data) && (int) $data['security_level'] !== $user->security_level) {
            if ($user->id === $actor->id) {
                throw new DomainException('You cannot change your own security level.');
            }
            $this->assertLevelGrantable($actor, (int) $data['security_level']);
        }
        if (array_key_exists('is_active', $data) && ! $data['is_active'] && $user->id === $actor->id) {
            throw new DomainException('You cannot deactivate your own account.');
        }

        if (! empty($data['is_active']) && ! $user->is_active) {
            app(LicenseManager::class)->assertWithin('users', User::where('is_active', true)->where('security_level', '<', SecurityLevel::Platform->value)->count(), 'active users');
        }
        $user->fill(array_intersect_key($data, array_flip(['name', 'username', 'email', 'security_level', 'is_active'])));
        $user->forceFill(array_intersect_key($data, array_flip(['phone', 'signin_method'])));
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $this->assertSignIn($user);
        $this->assertUnique($user);
        $this->recorder->save($actor, $user, 'user.updated', $reason);
        $this->authz->forget($user);

        if (! $user->is_active) {
            $user->tokens()->delete(); // signed-out everywhere, including tills
        }

        return $user;
    }

    /** Self-service profile edits (name, email, password). The screen asks for the current password first. */
    public function updateOwnProfile(Actor $actor, User $user, array $data): User
    {
        if ($actor->id === null || $actor->id !== $user->id) {
            throw new AccessDenied('You can only change your own profile here.');
        }
        $user->fill(array_intersect_key($data, array_flip(['name', 'email'])));
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $this->assertUnique($user);

        return $this->recorder->save($actor, $user, 'user.profile_updated');
    }

    public function setPin(Actor $actor, User $user, string $pin): void
    {
        if ($user->id !== $actor->id) {
            $this->require($actor, 'admin.users.manage');
            $this->assertSameTenant($actor, $user);
            $this->assertLevelGrantable($actor, $user->security_level);
        }
        if (! preg_match('/^\d{4,8}$/', $pin)) {
            throw new DomainException('A PIN is 4 to 8 digits.');
        }
        if (in_array($pin, ['0000', '1111', '1234', '12345', '123456', '000000', '4321'], true)) {
            throw new DomainException('That PIN is too easy to guess.');
        }
        $user->pin_hash = Hash::make($pin);
        $user->pin_failed_attempts = 0;
        $user->pin_locked_until = null;
        $this->recorder->save($actor, $user, 'user.pin_set');
    }

    /** @param list<string> $permissionKeys */
    public function saveRole(Actor $actor, Role $role, array $attributes, array $permissionKeys, ?string $reason = null): Role
    {
        $this->require($actor, 'admin.roles.manage');
        if ($role->exists) {
            $this->assertSameTenant($actor, $role);
        }
        $level = (int) ($attributes['security_level'] ?? $role->security_level);
        $this->assertLevelGrantable($actor, $level);
        if ($role->exists) {
            $this->assertLevelGrantable($actor, $role->security_level);
        }

        $technical = (bool) ($attributes['is_technical'] ?? $role->is_technical);
        $perms = Permission::whereIn('key', $permissionKeys)->get();
        if ($perms->count() !== count(array_unique($permissionKeys))) {
            throw new DomainException('Unknown permission selected.');
        }
        if ($technical && $perms->contains(fn ($p) => ! $p->is_technical)) {
            throw new DomainException('A technical role (such as IT Admin) cannot hold business permissions.');
        }
        foreach ($perms as $p) {
            if ($p->min_level > $level) {
                throw new DomainException("“{$p->label}” needs at least L{$p->min_level}; this role is L{$level}.");
            }
        }
        // You can only hand out what you hold company-wide yourself — otherwise editing a role is a back door.
        $before = $role->exists ? $role->permissionKeys() : [];
        $added = array_diff($permissionKeys, $before);
        foreach ($added as $key) {
            if (! $this->holdsCompanyWide($actor, $key)) {
                throw new AccessDenied('You can only add permissions you hold company-wide yourself.');
            }
        }

        return $this->recorder->transaction(function () use ($actor, $role, $attributes, $permissionKeys, $before, $level, $technical, $reason) {
            $role->fill(array_intersect_key($attributes, array_flip(['key', 'name', 'description', 'default_limits'])));
            $role->security_level = $level;
            $role->is_technical = $technical;
            if (! $role->exists) {
                $role->tenant_id = $actor->tenantId;
            }
            $permsChanged = array_diff($permissionKeys, $before) || array_diff($before, $permissionKeys);
            $this->recorder->save($actor, $role, $role->exists ? 'role.updated' : 'role.created', $reason);
            if ($permsChanged) {
                $role->permissions()->sync($permissionKeys);
                $this->recorder->audit->record($actor, 'role.permissions_changed', $role, [
                    'added' => [null, array_values(array_diff($permissionKeys, $before))],
                    'removed' => [null, array_values(array_diff($before, $permissionKeys))],
                ], $reason);
                $this->recorder->outbox->publish($actor, $role, 'role.permissions_changed', ['permissions' => $permissionKeys]);
            }
            $this->authz->forget();

            return $role;
        });
    }

    public function grant(
        Actor $actor,
        User $user,
        Role $role,
        ScopeType $scopeType,
        ?string $scopeId,
        array $limits = [],
        ?CarbonInterface $expiresAt = null,
        ?string $reason = null,
    ): RoleAssignment {
        $this->require($actor, 'admin.roles.manage');
        $this->assertSameTenant($actor, $user);
        $this->assertSameTenant($actor, $role);

        if ($user->id === $actor->id) {
            throw new DomainException('You cannot grant access to yourself. Ask another administrator.');
        }
        if ($role->security_level > $user->security_level) {
            throw new DomainException("{$role->name} is L{$role->security_level}; {$user->name} is only cleared to L{$user->security_level}.");
        }
        $this->assertLevelGrantable($actor, $role->security_level);
        if ($expiresAt && ! $reason) {
            throw new DomainException('Temporary access needs a reason.');
        }
        if ($expiresAt && $expiresAt->isPast()) {
            throw new DomainException('The expiry must be in the future.');
        }

        $scope = $this->resolveScope($actor, $scopeType, $scopeId);
        // The granter must hold every permission of the role over that scope, through their own roles. Parts the licence
        // leaves out still count (Cashier includes HR's "request leave"): the licence limits use, not who may grant.
        foreach ($role->permissionKeys() as $key) {
            if (! $this->actorHolds($actor, $key, $scope)) {
                throw new AccessDenied("You can't grant {$role->name} here: you don't hold all of its permissions in that scope.");
            }
        }
        foreach ($limits as $k => $v) {
            if (! is_int($v) || $v < 0) {
                throw new DomainException("Limit {$k} must be a whole, non-negative number.");
            }
        }

        $assignment = new RoleAssignment([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => $scopeType,
            'scope_id' => $scope->getKey(),
            'limits' => $limits ?: ($role->default_limits ?? []),
            'starts_at' => now(),
            'expires_at' => $expiresAt,
            'reason' => $reason,
        ]);
        $assignment->tenant_id = $actor->tenantId;
        $assignment->granted_by = $actor->id;
        $assignment->granted_by_name = $actor->name;

        $this->recorder->save($actor, $assignment, $expiresAt ? 'access.granted_temporary' : 'access.granted', $reason);
        $this->authz->forget($user);

        return $assignment;
    }

    public function revoke(Actor $actor, RoleAssignment $assignment, string $reason): void
    {
        $this->require($actor, 'admin.roles.manage');
        $this->assertSameTenant($actor, $assignment);
        if ($assignment->revoked_at) {
            return;
        }
        if (trim($reason) === '') {
            throw new DomainException('Revoking access needs a reason.');
        }
        $this->assertLevelGrantable($actor, $assignment->role->security_level);

        $assignment->revoked_at = now();
        $assignment->revoked_by = $actor->id;
        $assignment->revoke_reason = $reason;
        $this->recorder->save($actor, $assignment, 'access.revoked', $reason);
        $this->authz->forget($assignment->user);
    }

    // --- rules -------------------------------------------------------------

    private function require(Actor $actor, string $permission): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, $permission)) {
            throw new AccessDenied;
        }
    }

    /** The sign-in method is one of the known ones; password only is for low-risk staff; a WhatsApp number must look right. */
    private function assertSignIn(User $user): void
    {
        $user->signin_method = $user->signin_method ?: 'default';
        if (! array_key_exists($user->signin_method, User::SIGNIN_METHODS)) {
            throw new DomainException('Choose how the person signs in.');
        }
        if ($user->signin_method === 'password' && $user->security_level > User::PASSWORD_ONLY_MAX_LEVEL) {
            throw new DomainException('Password only is for staff up to level L'.User::PASSWORD_ONLY_MAX_LEVEL.' (supervisors). Managers and above approve money and access, so they sign in with a second step.');
        }
        if ($user->phone !== null && $user->phone !== '' && ! Phone::looksValid($user->phone)) {
            throw new DomainException('That phone number does not look right.');
        }
        $user->phone = $user->phone ?: null;
    }

    /** Nobody hands out a level above their own (owners may create co-owners at L6). */
    private function assertLevelGrantable(Actor $actor, int $level): void
    {
        $own = $actor->user?->security_level ?? SecurityLevel::Owner->value;
        if ($level > $own || $level >= SecurityLevel::Platform->value) {
            throw new AccessDenied("You can't work with level L{$level}; your own level is L{$own}.");
        }
    }

    private function holdsCompanyWide(Actor $actor, string $permission): bool
    {
        return $actor->user === null || $this->authz->allows($actor->user, $permission);
    }

    private function actorHolds(Actor $actor, string $permission, Model $scope): bool
    {
        if ($actor->user === null) {
            return true; // installer bootstrap only (no human can be the actor yet)
        }

        return $scope instanceof Tenant
            ? $this->authz->holds($actor->user, $permission)
            : $this->authz->holds($actor->user, $permission, $scope);
    }

    private function resolveScope(Actor $actor, ScopeType $type, ?string $id): Model
    {
        $model = match ($type) {
            ScopeType::Tenant => Tenant::find($actor->tenantId),
            ScopeType::LegalEntity => LegalEntity::withoutGlobalScopes()->where('tenant_id', $actor->tenantId)->find($id),
            ScopeType::Industry => Industry::withoutGlobalScopes()->where('tenant_id', $actor->tenantId)->find($id),
            ScopeType::Branch => Branch::withoutGlobalScopes()->where('tenant_id', $actor->tenantId)->find($id),
            ScopeType::Warehouse => Warehouse::withoutGlobalScopes()->where('tenant_id', $actor->tenantId)->find($id),
            ScopeType::Till => Till::withoutGlobalScopes()->where('tenant_id', $actor->tenantId)->find($id),
        };
        if (! $model) {
            throw new DomainException('Choose where this access applies.');
        }

        return $model;
    }

    private function assertSameTenant(Actor $actor, Model $model): void
    {
        if ($model->getAttribute('tenant_id') !== $actor->tenantId) {
            throw new AccessDenied;
        }
    }

    private function assertUnique(User $user): void
    {
        $clash = User::where('id', '!=', $user->id ?? '00000000-0000-0000-0000-000000000000')
            ->where(fn ($q) => $q->where('email', $user->email)
                ->orWhere(fn ($q) => $q->where('tenant_id', $user->tenant_id)->where('username', $user->username)))
            ->exists();
        if ($clash) {
            throw new DomainException('That username or email is already in use.');
        }
    }
}
