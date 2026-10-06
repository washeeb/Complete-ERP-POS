<?php

use App\Models\User;
use App\Models\Warehouse;
use App\Security\Authorizer;
use Illuminate\Support\Facades\Broadcast;

/*
 * Who may listen to which live channel. Payloads carry ids only; listeners re-read details through
 * their own permission-checked APIs, but tenants are still kept strictly apart here.
 * (Storefront channels "storefront.{id}" are public and carry public prices/availability only.)
 */

Broadcast::channel('tenant.{tenantId}.catalog', fn (User $user, string $tenantId) => $user->tenant_id === $tenantId && $user->is_active);

Broadcast::channel('tenant.{tenantId}.stock.{warehouseId}', function (User $user, string $tenantId, string $warehouseId) {
    $warehouse = Warehouse::withoutGlobalScopes()->find($warehouseId);

    return $user->tenant_id === $tenantId && $user->is_active && $warehouse?->tenant_id === $tenantId;
});

Broadcast::channel('tenant.{tenantId}.orders', fn (User $user, string $tenantId) => $user->tenant_id === $tenantId
    && app(Authorizer::class)->allowsAnywhere($user, 'orders.view'));
