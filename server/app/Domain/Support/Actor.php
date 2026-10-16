<?php

namespace App\Domain\Support;

use App\Models\User;

/**
 * Who is making a change. Captured from the server side (never trusted from request
 * bodies) and snapshotted by name into audit rows so renaming a user never rewrites history.
 */
final class Actor
{
    public function __construct(
        public readonly ?string $id,
        public readonly string $name,
        public readonly string $source, // web | api | pos | cli | system | sync
        public readonly string $tenantId,
        public readonly ?string $deviceId = null,
        public readonly ?string $ip = null,
        public readonly ?string $requestId = null,
        public readonly ?User $user = null,
    ) {}

    public static function user(User $user, string $source = 'web', ?string $deviceId = null): self
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return new self(
            id: $user->id,
            name: $user->name,
            source: $source,
            tenantId: $user->tenant_id,
            deviceId: $deviceId,
            ip: $request?->ip(),
            requestId: $request?->attributes->get('request_id'),
            user: $user,
        );
    }

    /** Named service identity for installers, migrations and jobs — never a fabricated human. */
    public static function system(string $tenantId, string $name, string $source = 'system'): self
    {
        return new self(id: null, name: $name, source: $source, tenantId: $tenantId);
    }
}
