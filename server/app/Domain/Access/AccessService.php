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
use App\Support\Dashboard\Panels;
use App\Support\Look;
use App\Support\NotifyPrefs;
use App\Support\Settings;
use App\Support\UserPrefs;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/** Users, roles and grants. Every rule here exists to stop privilege escalation. */
final class AccessService
{
    /** Where the profile page leaves a new photo until it is saved (then cleaned into ProfilePhoto::DIR). */
    public const PHOTO_UPLOADS = 'avatar-uploads';

    /** Lowest level treated as a manager for company-wide access; read-only auditors look at the whole company by nature. */
    private const MANAGER_LEVEL = 4;

    /** Most tests set up staff company-wide for brevity; the test of this rule switches it back on. */
    public static bool $limitCompanyWide = true;

    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz) {}

    public function createUser(Actor $actor, array $data): User
    {
        $this->require($actor, 'admin.users.manage');
        $level = (int) $data['security_level'];
        $this->assertLevelGrantable($actor, $level);
        app(LicenseManager::class)->assertWithin('users', User::licensedCount(), 'active users');

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
            app(LicenseManager::class)->assertWithin('users', User::licensedCount(), 'active users');
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
        if (array_key_exists('ui_look', $data)) { // '' = the company's choice
            $look = $data['ui_look'] ?: null;
            if ($look !== null && ! isset(Look::LOOKS[$look])) {
                throw new DomainException('Choose one of the looks offered.');
            }
            $user->ui_look = $look;
        }
        if (array_key_exists('ui_prefs', $data)) { // home page, rows, light/dark, dates, density — only allowed values
            $user->ui_prefs = UserPrefs::clean((array) $data['ui_prefs'], $user);
        }
        if (array_key_exists('notify_prefs', $data)) { // topics and channels — only the ones that exist
            $user->notify_prefs = NotifyPrefs::clean((array) $data['notify_prefs']);
        }
        // The photo field holds the current photo (unchanged), a new upload waiting on the private disk, or nothing.
        $old = $user->avatar_path;
        if (array_key_exists('photo', $data) && $data['photo'] !== $old) {
            $upload = $data['photo'];
            if ($upload !== null && ! str_starts_with((string) $upload, self::PHOTO_UPLOADS.'/')) {
                throw new DomainException('Upload the photo again.');
            }
            $user->avatar_path = $upload === null ? null : ProfilePhoto::store($user, (string) Storage::disk('local')->get($upload));
            if ($upload !== null) {
                Storage::disk('local')->delete($upload);
            }
        }
        $this->assertUnique($user);

        $saved = $this->recorder->save($actor, $user, $user->avatar_path !== $old ? 'user.photo_changed' : 'user.profile_updated');
        if ($user->avatar_path !== $old) {
            ProfilePhoto::delete($old);
        }

        return $saved;
    }

    /**
     * The person's own dashboard: the panels in their order and size, or null to go back to their role's. Only panels
     * of the catalogue are kept (App\Support\Dashboard\Panels); whether a panel shows is still decided by its own
     * permissions each time the dashboard opens.
     *
     * @param  list<array{key: string, size?: string}>|null  $panels
     */
    public function updateOwnDashboard(Actor $actor, User $user, ?array $panels): User
    {
        if ($actor->id === null || $actor->id !== $user->id) {
            throw new AccessDenied('You can only change your own dashboard.');
        }
        $user->dashboard = $panels === null ? null : Panels::clean($panels);

        return $this->recorder->save($actor, $user, 'user.dashboard_changed');
    }

    public const MAX_FAVOURITES = 20;

    /**
     * Adds the page to the person's favourites (the star on the menu band), or takes it off if it is there. Only pages
     * of this back office are kept, as an address without the site name, so a favourite can never lead elsewhere.
     *
     * @return list<array{url: string, label: string}>
     */
    public function toggleOwnFavourite(Actor $actor, User $user, string $url, string $label): array
    {
        if ($actor->id === null || $actor->id !== $user->id) {
            throw new AccessDenied('You can only change your own favourites.');
        }
        $parts = parse_url($url);
        $path = '/'.ltrim((string) ($parts['path'] ?? ''), '/');
        if ($parts === false || isset($parts['scheme']) && ($parts['host'] ?? null) !== request()->getHost() || ! preg_match('#^/admin(/|$)#', $path)) {
            throw new DomainException('Only pages of the back office can be favourites.');
        }
        $url = $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
        $label = mb_substr(trim(strip_tags($label)), 0, 80) ?: $path;

        $list = collect($user->ui_favourites ?? []);
        if ($list->contains('url', $url)) {
            $list = $list->reject(fn ($f) => $f['url'] === $url);
        } else {
            if ($list->count() >= self::MAX_FAVOURITES) {
                throw new DomainException('You have '.self::MAX_FAVOURITES.' favourites already. Remove one first.');
            }
            $list->push(['url' => $url, 'label' => $label]);
        }
        $user->ui_favourites = $list->values()->all();
        $this->recorder->save($actor, $user, 'user.favourites_changed');

        return $user->ui_favourites;
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

    /**
     * Clear someone's authenticator app (lost or deleted phone app): at the next sign-in they set it up again. A manager
     * may do this for someone at or below their own level, never for themselves; the owner, with nobody above, is reset
     * on the server (erp:mfa-reset), where the actor is the server itself. Their open sessions end.
     */
    public function resetMfa(Actor $actor, User $user, string $reason): void
    {
        if ($actor->id !== null) {
            $this->require($actor, 'admin.users.manage');
            $this->assertSameTenant($actor, $user);
            if ($user->id === $actor->id) {
                throw new DomainException('You cannot reset your own two-step sign-in. Ask a manager, or SyncLytics support.');
            }
            $this->assertLevelGrantable($actor, $user->security_level);
        }
        if (trim($reason) === '') {
            throw new DomainException('Say why (for the audit log).');
        }
        $user->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null, 'remember_token' => null]);
        $this->recorder->transaction(function () use ($actor, $user, $reason) {
            $this->recorder->save($actor, $user, 'user.mfa_reset', mb_substr(trim($reason), 0, 255));
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });
    }

    /** The prefix of a staff card's code, so a till can tell a scanned card from a typed username. */
    public const BADGE_PREFIX = 'SLB1-';

    /**
     * A new staff card for till sign-in: the person scans it instead of typing her username, then types her PIN (the card
     * alone never signs anyone in). Only a hash is kept, so the code is shown once, to print; a new card replaces the old.
     */
    public function issueBadge(Actor $actor, User $user): string
    {
        $this->require($actor, 'admin.users.manage');
        $this->assertSameTenant($actor, $user);
        if ($user->id !== $actor->id) {
            $this->assertLevelGrantable($actor, $user->security_level);
        }
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O or 1/I: a code read out loud is not mistaken
        $code = self::BADGE_PREFIX.implode('', array_map(fn () => $alphabet[random_int(0, 31)], range(1, 20)));
        $user->forceFill(['badge_hash' => self::badgeHash($code), 'badge_issued_at' => now()]);
        $this->recorder->transaction(fn () => $this->recorder->save($actor, $user, $user->getOriginal('badge_hash') ? 'user.badge_replaced' : 'user.badge_issued'));

        return $code;
    }

    /** A lost card: it stops working at once. */
    public function revokeBadge(Actor $actor, User $user): void
    {
        $this->require($actor, 'admin.users.manage');
        $this->assertSameTenant($actor, $user);
        $user->forceFill(['badge_hash' => null, 'badge_issued_at' => null]);
        $this->recorder->transaction(fn () => $this->recorder->save($actor, $user, 'user.badge_revoked'));
    }

    public static function isBadge(string $text): bool
    {
        return str_starts_with(strtoupper(trim($text)), self::BADGE_PREFIX);
    }

    public static function badgeHash(string $code): string
    {
        return hash('sha256', strtoupper(trim($code)));
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
        if (($attributes['key'] ?? $role->key) === 'owner' && $perms->contains(fn ($p) => $p->is_technical && ! in_array($p->key, OwnerSeparation::OWNER_MAY_SEE, true))
            && OwnerSeparation::hasTechnicalAdmin($actor->tenantId)) {
            throw new DomainException('The Owner role runs the business. Users, roles, devices and system settings are looked after by IT Admin and SyncLytics support, so a stolen owner sign-in cannot reach them.');
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
        // Owner and technical access stay on different accounts: stolen owner details must not reach the technical side.
        if ($role->is_technical && OwnerSeparation::isOwnerAccount($user)) {
            throw new DomainException("{$user->name} is an owner. Technical access goes on a separate account (an IT person, or SyncLytics support).");
        }
        if ($role->key === 'owner' && RoleAssignment::withoutGlobalScopes()->where('user_id', $user->id)->whereNull('revoked_at')->whereHas('role', fn ($q) => $q->withoutGlobalScopes()->where('is_technical', true))->exists()) {
            throw new DomainException("{$user->name} has technical (IT) access. An owner's account cannot also be an IT account: use separate accounts.");
        }
        $this->assertLevelGrantable($actor, $role->security_level);
        if (self::$limitCompanyWide && in_array($scopeType, [ScopeType::Tenant, ScopeType::LegalEntity], true) && ! self::mayBeCompanyWide($role)) {
            throw new DomainException("{$role->name} can't have company-wide access. Give it for a branch or an industry.");
        }
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
        if ($role->key === 'it_admin') {
            app(OwnerSeparation::class)->apply($actor->tenantId, "IT Admin given to {$user->name}: the owner's technical access moves to them");
        }

        return $assignment;
    }

    /** The rule on who can see the whole company (Settings → Security). */
    public static function mayBeCompanyWide(Role $role): bool
    {
        if (in_array($role->key, ['owner', 'superadmin'], true)) {
            return true;
        }
        if (app(Settings::class)->get('access.company_wide', $role->tenant_id) === 'owner') {
            return false;
        }

        return $role->security_level >= self::MANAGER_LEVEL || $role->key === 'auditor';
    }

    /** Replaces company-wide access with the same role over a branch or industry, as one step. */
    public function narrow(Actor $actor, RoleAssignment $assignment, ScopeType $scopeType, string $scopeId): RoleAssignment
    {
        if (! in_array($scopeType, [ScopeType::Branch, ScopeType::Industry], true)) {
            throw new DomainException('Choose a branch or an industry.');
        }

        return $this->recorder->transaction(function () use ($actor, $assignment, $scopeType, $scopeId) {
            $reason = "Limited to one {$scopeType->value}";
            $new = $this->grant($actor, $assignment->user, $assignment->role, $scopeType, $scopeId, $assignment->limits ?? [], $assignment->expires_at, $assignment->reason ?? $reason);
            $this->revoke($actor, $assignment, $reason);

            return $new;
        });
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
        // Someone must always be able to add users and fix access: never revoke the last technical administrator.
        if (in_array($assignment->role->key, ['it_admin', 'superadmin'], true)
            && ! RoleAssignment::withoutGlobalScopes()->where('tenant_id', $assignment->tenant_id)->where('id', '<>', $assignment->id)->whereNull('revoked_at')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->whereHas('role', fn ($q) => $q->withoutGlobalScopes()->whereIn('key', ['it_admin', 'superadmin']))->exists()) {
            throw new DomainException('This is the last IT administrator. Give IT Admin to someone else (or set up the SyncLytics support account) first, so somebody can still add users and fix access.');
        }

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
