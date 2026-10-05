<?php

namespace App\Domain\Org;

use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Security\AccessCatalogSync;
use App\Security\ScopeType;
use App\Security\SecurityLevel;
use Illuminate\Support\Str;

/**
 * Bootstraps a company and its first owner. This is the only place a grant is made without
 * a human granter (there is nobody yet); the audit row names the installer explicitly.
 * Everything after this goes through AccessService.
 */
final class TenantInstaller
{
    public function __construct(private readonly Recorder $recorder, private readonly AccessCatalogSync $catalog) {}

    /** @return array{0: Tenant, 1: User} */
    public function install(string $companyName, array $owner): array
    {
        if (User::where('email', strtolower($owner['email']))->exists()) {
            throw new DomainException('That email already has an account.');
        }
        if (strlen($owner['password']) < 12) {
            throw new DomainException('The owner password must be at least 12 characters.');
        }
        $newPermissions = $this->catalog->syncPermissions();

        return $this->recorder->transaction(function () use ($companyName, $owner, $newPermissions) {
            $tenant = new Tenant(['name' => $companyName, 'slug' => $this->uniqueSlug($companyName)]);
            $tenant->save();
            $actor = Actor::system($tenant->id, 'Installer (php artisan erp:install)', 'cli');
            $this->recorder->audit->record($actor, 'tenant.created', $tenant, ['name' => [null, $companyName]]);
            $this->recorder->outbox->publish($actor, $tenant, 'tenant.created', $tenant->toArray());

            $this->catalog->syncRoles($tenant->id, $newPermissions);

            $user = new User;
            $user->forceFill([
                'tenant_id' => $tenant->id,
                'name' => $owner['name'],
                'username' => strtolower($owner['username']),
                'email' => strtolower($owner['email']),
                'password' => $owner['password'],
                'security_level' => SecurityLevel::Owner->value,
                'is_active' => true,
            ]);
            $this->recorder->save($actor, $user, 'user.created');

            $role = Role::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('key', 'owner')->firstOrFail();
            $grant = new RoleAssignment([
                'user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => ScopeType::Tenant,
                'scope_id' => $tenant->id, 'starts_at' => now(), 'reason' => 'Initial owner at installation',
            ]);
            $grant->tenant_id = $tenant->id;
            $grant->granted_by = $user->id; // no human granter exists; the audit row names the installer
            $grant->granted_by_name = $actor->name;
            $this->recorder->save($actor, $grant, 'access.granted', 'Initial owner at installation');

            return [$tenant, $user];
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'company';
        $slug = $base;
        for ($i = 2; Tenant::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
