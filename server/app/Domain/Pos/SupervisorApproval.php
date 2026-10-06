<?php

namespace App\Domain\Pos;

use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Device;
use App\Models\User;
use App\Security\Authorizer;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * A supervisor approves something on a till by entering their own username and PIN (spec §7: approval by PIN).
 * Same lockout as till sign-in; the approver must hold the permission (and limit) for the target, and cannot be
 * the cashier asking.
 */
final class SupervisorApproval
{
    public function __construct(private readonly Authorizer $authz, private readonly Recorder $recorder, private readonly Settings $settings) {}

    public function verify(Device $device, array $approval, string $permission, Model $target, ?string $limitKey, int $amountMinor, string $cashierId): User
    {
        $failed = new DomainException('Supervisor username or PIN is wrong.', 'approval_failed');
        $user = User::where('tenant_id', $device->tenant_id)->where('username', strtolower(trim((string) ($approval['username'] ?? ''))))->first();
        if (! $user || ! $user->is_active || ! $user->pin_hash) {
            Hash::check((string) ($approval['pin'] ?? ''), '$2y$12$'.str_repeat('a', 53));
            throw $failed;
        }
        if ($user->pin_locked_until?->isFuture()) {
            throw new DomainException('That supervisor’s PIN is locked after too many wrong attempts.', 'pin_locked');
        }
        if (! Hash::check((string) ($approval['pin'] ?? ''), $user->pin_hash)) {
            $this->recorder->transaction(function () use ($user, $device) {
                $user->pin_failed_attempts++;
                if ($user->pin_failed_attempts >= (int) $this->settings->get('security.pin_max_attempts', $user->tenant_id)) {
                    $user->pin_locked_until = now()->addMinutes((int) $this->settings->get('security.pin_lockout_minutes', $user->tenant_id));
                    $user->pin_failed_attempts = 0;
                }
                $this->recorder->save(new Actor(null, "Device {$device->name}", 'pos', $device->tenant_id, deviceId: $device->id), $user, 'pos.approval_failed',
                    branchId: $device->branch_id);
            });
            throw $failed;
        }
        if ($user->id === $cashierId) {
            throw new DomainException('Another person must approve this.', 'own_document');
        }
        if (! $this->authz->allows($user, $permission, $target)
            || ($limitKey && ! $this->authz->allowsAmount($user, $permission, $target, $limitKey, $amountMinor))) {
            throw new DomainException("{$user->name} cannot approve this (permission or limit).", 'over_limit');
        }

        return $user;
    }
}
