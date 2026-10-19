<?php

namespace App\Http\Middleware;

use App\Domain\Devices\PosAuthService;
use App\Models\Device;
use App\Models\User;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * mode "device": caller is an enrolled device (its own token).
 * mode "pos" / "handheld": caller is a person signed in on a till / warehouse handheld; the token must be bound to a device
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
            in_array($mode, ['pos', 'handheld'], true) && $principal instanceof User && $token?->can($mode) => $this->boundDevice($token->abilities ?? []),
            default => null,
        };

        if (! $device || ! $device->isActive() || $device->tenant_id !== $principal->tenant_id
            || ($principal instanceof User && ! $principal->is_active)) {
            return response()->json(['error' => 'device_revoked', 'detail' => 'This device or session is no longer allowed. Sign in again or re-enrol the device.'], 401);
        }

        app(TenantContext::class)->set($device->tenant_id);
        $request->attributes->set('device', $device);
        if ($mode !== 'device') {
            $this->keepAlive($token);
        }

        return $next($request);
    }

    /**
     * A till sign-in lasts 12 hours from its last use, not from the morning sign-in: a long trading day never signs the
     * cashier out mid-sale, while a till left idle still ends. Written at most every 15 minutes.
     */
    private function keepAlive(mixed $token): void
    {
        if (! $token instanceof PersonalAccessToken || ! $token->expires_at) {
            return;
        }
        $until = now()->addHours(PosAuthService::TOKEN_HOURS);
        if ($token->expires_at->lt($until->copy()->subMinutes(15))) {
            $token->forceFill(['expires_at' => $until])->save();
        }
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
