<?php

namespace App\Domain\Access;

use App\Domain\Messaging\Notifier;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Security\Authorizer;
use App\Security\ScopeType;
use App\Security\SecurityLevel;

/**
 * The SyncLytics support account (Superadmin, level L7 — owner decision 2026-10-22): one per company, made on the
 * server by SyncLytics with `php artisan erp:support-account`, never from the back office. Above the owner, so nobody in
 * the company can change or lock it out by mistake; but it always signs in with the authenticator app, it does not count
 * as a licensed user, every sign-in is in the audit log, and the owner and general manager are told each time.
 */
final class SupportAccount
{
    public const USERNAME = 'synclytics';

    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly Notifier $notifier) {}

    /** Create the account, or update its name, e-mail and password. Returns the account and whether it was new. */
    public function ensure(Tenant $tenant, string $email, string $name, string $password): array
    {
        $email = mb_strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('That e-mail address does not look right.');
        }
        if (mb_strlen($password) < 12) {
            throw new DomainException('Use at least 12 characters for the password.');
        }
        $other = User::where('email', $email)->where(fn ($q) => $q->where('tenant_id', '<>', $tenant->id)->orWhere('security_level', '<', SecurityLevel::Platform->value))->first();
        if ($other) {
            throw new DomainException("{$email} is already used by {$other->name}. Use another address for the support account (e.g. support+".$tenant->slug.'@…).');
        }
        $actor = Actor::system($tenant->id, 'SyncLytics (php artisan erp:support-account)', 'cli');

        return $this->recorder->transaction(function () use ($tenant, $email, $name, $password, $actor) {
            $user = User::where('tenant_id', $tenant->id)->where('security_level', SecurityLevel::Platform->value)->first();
            $isNew = ! $user;
            $user ??= new User;
            $user->forceFill(['tenant_id' => $tenant->id, 'name' => $name, 'username' => self::USERNAME, 'email' => $email, 'password' => $password,
                'security_level' => SecurityLevel::Platform->value, 'is_active' => true, 'signin_method' => 'app']);
            $this->recorder->save($actor, $user, $isNew ? 'support.account_created' : 'support.account_updated');

            $role = Role::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('key', 'superadmin')->first()
                ?? throw new DomainException('The Superadmin role is missing: run php artisan db:seed --force first.');
            if (! RoleAssignment::withoutGlobalScopes()->where('user_id', $user->id)->where('role_id', $role->id)->whereNull('revoked_at')->exists()) {
                $grant = new RoleAssignment(['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => ScopeType::Tenant, 'scope_id' => $tenant->id,
                    'starts_at' => now(), 'reason' => 'SyncLytics support account', 'limits' => $role->default_limits ?? []]);
                $grant->tenant_id = $tenant->id;
                $grant->granted_by = $user->id;
                $grant->granted_by_name = $actor->name;
                $this->recorder->save($actor, $grant, 'access.granted', 'SyncLytics support account');
            }
            $this->authz->forget($user);
            $this->notifier->toPermission($tenant->id, 'support.access.grant', null, $isNew ? 'SyncLytics support account added' : 'SyncLytics support account changed',
                "{$name} ({$email}) can sign in to support your business. It always uses an authenticator app, and you are told each time it signs in.", null, [$user->id]);

            return [$user->fresh(), $isNew];
        });
    }

    /** Called on every back-office sign-in: support signing in is told to the owner and general manager. */
    public function signedIn(User $user): void
    {
        if (! $user->isSupport()) {
            return;
        }
        $this->notifier->toPermission($user->tenant_id, 'support.access.grant', null, 'SyncLytics support signed in',
            "{$user->name} signed in to the back office at ".now()->format('H:i').'. Everything they do is in the audit log.', null, [$user->id]);
    }
}
