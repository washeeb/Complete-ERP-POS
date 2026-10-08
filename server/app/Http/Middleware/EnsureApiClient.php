<?php

namespace App\Http\Middleware;

use App\Domain\Api\ApiClients;
use App\Models\ApiClient;
use App\Models\User;
use App\Security\Authorizer;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public API: the caller is an active API client whose key carries the scope, and the person it acts as still holds the
 * matching permission. Sets the company context; the run-as person is available to the controller.
 */
class EnsureApiClient
{
    public function __construct(private readonly Authorizer $authz) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $client = $request->user();
        $token = $client?->currentAccessToken();
        if (! $client instanceof ApiClient || ! $client->is_active) {
            return response()->json(['error' => 'unauthenticated', 'detail' => 'Send a valid API key: Authorization: Bearer <your key>'], 401);
        }
        $user = User::withoutGlobalScopes()->find($client->user_id);
        $permission = ApiClients::SCOPES[$scope][1] ?? null;
        if (! $token?->can($scope) || ! $user?->is_active || ($permission && ! $this->authz->allowsAnywhere($user, $permission))) {
            return response()->json(['error' => 'forbidden', 'detail' => "This key does not have the {$scope} scope (or the person it acts as lost the permission)."], 403);
        }
        app(TenantContext::class)->set($client->tenant_id);
        $request->attributes->set('api_user', $user);
        if (! $client->last_used_at || $client->last_used_at->lt(now()->subMinute())) {
            $client->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
