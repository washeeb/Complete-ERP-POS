<?php

namespace App\Support;

/** Request-scoped current tenant. Set explicitly (CLI, devices) or derived from the signed-in user. */
final class TenantContext
{
    private ?string $tenantId = null;

    public function set(?string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function id(): ?string
    {
        if ($this->tenantId) {
            return $this->tenantId;
        }
        // auth()->user() resolves the User model, which is deliberately not tenant-scoped (no recursion).
        $user = auth()->hasUser() || auth()->check() ? auth()->user() : null;

        return $user?->tenant_id;
    }
}
