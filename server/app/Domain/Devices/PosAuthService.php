<?php

namespace App\Domain\Devices;

use App\Domain\Access\AccessService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Till;
use App\Models\User;
use App\Security\Authorizer;
use App\Support\Settings;
use Illuminate\Support\Facades\Hash;

/**
 * Fast till sign-in by PIN — only on an enrolled, active device (the device is the second
 * factor), only for a named user who holds pos.access over that till.
 */
final class PosAuthService
{
    public const TOKEN_HOURS = 12;

    public const HANDHELD_PERMISSIONS = ['stock.receive', 'stock.transfer', 'stock.count'];

    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly Settings $settings,
        private readonly OfflineGrant $grants,
    ) {}

    public function pinLogin(Device $device, string $username, string $pin): array
    {
        $user = $this->verifyPin($device, $username, $pin);

        return $this->signIn($device, $user, $pin);
    }

    /** Check a person's PIN on this device (wrong PINs count towards the lockout). Used for sign-in and for clocking in/out. */
    public function verifyPin(Device $device, string $username, string $pin): User
    {
        if (! $device->isActive()) {
            throw new DomainException('This device is not enrolled or has been revoked.', 'device_revoked');
        }
        $failed = new DomainException('Wrong username or PIN.', 'invalid_credentials');
        // A scanned staff card stands in for the username; the PIN is still needed.
        $user = AccessService::isBadge($username)
            ? User::where('tenant_id', $device->tenant_id)->where('badge_hash', AccessService::badgeHash($username))->first()
            : User::where('tenant_id', $device->tenant_id)->where('username', strtolower($username))->first();
        $deviceActor = new Actor(id: null, name: "Device {$device->name}", source: 'pos', tenantId: $device->tenant_id,
            deviceId: $device->id, ip: request()->ip());

        if (! $user || ! $user->is_active || ! $user->pin_hash) {
            Hash::check($pin, '$2y$12$'.str_repeat('a', 53)); // equalise timing with the real check
            throw $failed;
        }
        if ($user->pin_locked_until?->isFuture()) {
            throw new DomainException('This PIN is locked after too many wrong attempts. Try later or ask a manager to reset it.', 'pin_locked');
        }

        if (! Hash::check($pin, $user->pin_hash)) {
            $this->recorder->transaction(function () use ($user, $deviceActor, $device) {
                $user->pin_failed_attempts++;
                $max = (int) $this->settings->get('security.pin_max_attempts', $user->tenant_id);
                if ($user->pin_failed_attempts >= $max) {
                    $user->pin_locked_until = now()->addMinutes((int) $this->settings->get('security.pin_lockout_minutes', $user->tenant_id));
                    $user->pin_failed_attempts = 0;
                }
                $this->recorder->save($deviceActor, $user, $user->pin_locked_until ? 'pos.pin_locked' : 'pos.login_failed', branchId: $device->branch_id);
            });
            throw $failed;
        }
        if ($user->pin_failed_attempts > 0) {
            $user->forceFill(['pin_failed_attempts' => 0])->saveQuietly();
        }

        return $user;
    }

    private function signIn(Device $device, User $user, string $pin): array
    {
        $handheld = $device->kind === 'handheld';
        if ($handheld) {
            // Warehouse handheld: for people who receive, move, transfer or count stock at this branch.
            if (! collect(self::HANDHELD_PERMISSIONS)->contains(fn ($p) => $this->authz->allows($user, $p, $device->branch))) {
                throw new AccessDenied('You are not set up for warehouse work at this branch.');
            }
        } else {
            $target = $device->till_id ? Till::find($device->till_id) : $device->branch;
            if (! $this->authz->allows($user, 'pos.access', $target)) {
                throw new AccessDenied($this->notHere($user, $device, $target));
            }
        }

        return $this->recorder->transaction(function () use ($user, $device, $pin, $handheld) {
            $user->pin_failed_attempts = 0;
            $user->last_login_at = now();
            $this->recorder->save(Actor::user($user, 'pos', $device->id), $user, 'pos.login', branchId: $device->branch_id);
            $device->seen();
            $token = $user->createToken(($handheld ? 'handheld' : 'pos').":{$device->id}", [$handheld ? 'handheld' : 'pos', "device:{$device->id}"], now()->addHours(self::TOKEN_HOURS));

            return [
                'token' => $token->plainTextToken,
                'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
                'user' => ['id' => $user->id, 'name' => $user->name, 'username' => $user->username],
                // Lets this person sign in on this till while it cannot reach the server.
                'offline_grant' => $this->grants->issue($user, $device, $pin), // tills and handhelds keep working, and sign in, while the server is out of reach
            ];
        });
    }

    /**
     * Why this person cannot sell here, and where they can (owner request 2026-10-23): "You are set up for Till 1 at Head
     * Office, not Till 1 at Branch A" tells the cashier and the manager what to fix.
     */
    private function notHere(User $user, Device $device, $target): string
    {
        $here = $target instanceof Till ? $target->name.' at '.(Branch::find($target->branch_id)?->name ?? 'this branch') : ($device->branch?->name ?? 'this branch');
        $where = Till::where('is_active', true)->get()->filter(fn (Till $t) => $this->authz->allows($user, 'pos.access', $t))
            ->map(fn (Till $t) => $t->name.' at '.(Branch::find($t->branch_id)?->name ?? '?'))->unique()->values();
        if ($where->isEmpty()) {
            return "You are not set up to use any till. A manager can add the Teller role for {$here} (People & access → Users → you).";
        }
        $list = $where->count() > 3 ? $where->take(3)->implode(', ').' and '.($where->count() - 3).' more' : $where->implode(', ');

        return "You are set up for {$list}, not {$here}. Use your own till, or ask a manager to add {$here} to your role (People & access → Users → you).";
    }
}
