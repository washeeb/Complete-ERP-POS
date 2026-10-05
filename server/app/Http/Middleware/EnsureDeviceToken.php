<?php

namespace App\Http\Middleware;

use App\Models\Device;
use App\Models\User;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * mode "device": caller is an enrolled device (its own token).
 * mode "pos":    caller is a person signed in on a till; the token must be bound to a device
 *                that is still active — revoking a device ends every session on it.
 */
class EnsureDeviceToken
{
    public function handle(Request $request, Closure $next, string $mode): Response
    {
        $principal = $request->user();
        $token = $principal?->currentAccessToken();

        $device = match (true) {
            $mode === 'device' && $principal instanceof Device && $token?->can('device') => $principal,
            $mode === 'pos' && $principal instanceof User && $token?->can('pos') => $this->boundDevice($token->abilities ?? []),
            default => null,
        };

        if (! $device || ! $device->isActive() || $device->tenant_id !== $principal->tenant_id
            || ($principal instanceof User && ! $principal->is_active)) {
            return response()->json(['error' => 'device_revoked', 'detail' => 'This device or session is no longer allowed. Sign in again or re-enrol the device.'], 401);
        }

        app(TenantContext::class)->set($device->tenant_id);
        $request->attributes->set('device', $device);

        return $next($request);
    }

    private function boundDevice(array $abilities): ?Device
    {
        foreach ($abilities as $a) {
            if (str_starts_with($a, 'device:')) {
                return Device::withoutGlobalScopes()->find(substr($a, 7));
            }
        }

        return null;
    }
}
