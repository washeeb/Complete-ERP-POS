<?php

namespace App\Domain\Devices;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Till;
use App\Security\Authorizer;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Devices are approved by being registered by an admin (who receives a one-time code),
 * then enrolled from the device itself with that code. Revoking kills every token
 * the device or anyone signed in on it holds.
 */
final class DeviceService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly Settings $settings,
    ) {}

    /** @return array{0: Device, 1: string} the device and its plaintext enrolment code (shown once) */
    public function register(Actor $actor, array $data): array
    {
        $branch = Branch::findOrFail($data['branch_id']);
        $this->require($actor, $branch);
        if (! array_key_exists($data['kind'] ?? '', Device::KINDS)) {
            throw new DomainException('Choose the kind of device.');
        }
        if (! empty($data['till_id'])) {
            $till = Till::findOrFail($data['till_id']);
            if ($till->branch_id !== $branch->id) {
                throw new DomainException('That till is in a different branch.');
            }
        }

        $device = new Device(array_intersect_key($data, array_flip(['branch_id', 'till_id', 'name', 'kind'])));
        $device->tenant_id = $actor->tenantId;
        $device->status = 'pending';
        $device->created_by = $actor->id;
        if ($device->till_id) {
            // Own receipt series per device on a till, so numbers never collide (reinstalls, backup tablets).
            $used = Device::withoutGlobalScopes()->where('till_id', $device->till_id)->count();
            $device->receipt_series = self::series($used);
        }
        $code = $this->issueCode($actor, $device);
        $this->recorder->save($actor, $device, 'device.registered', branchId: $branch->id);

        return [$device, $code];
    }

    public function reissueCode(Actor $actor, Device $device): string
    {
        $this->require($actor, $device->branch);
        if ($device->status !== 'pending') {
            throw new DomainException('Only a device that has not enrolled yet can get a new code. Register a replacement device instead.');
        }
        $code = $this->issueCode($actor, $device);
        $this->recorder->save($actor, $device, 'device.code_reissued', branchId: $device->branch_id);

        return $code;
    }

    /** Called by the device itself (unauthenticated, rate-limited). Returns the device token. */
    public function enroll(string $code, string $publicKey): array
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        $generic = new DomainException('Enrolment failed. Check the code or ask an administrator for a new one.', 'enrollment_failed');
        if (strlen($normalized) !== 12) {
            throw $generic;
        }
        // The first block is stored in clear to find the device; the whole code is verified by hash.
        $device = Device::withoutGlobalScopes()->where('enrollment_lookup', substr($normalized, 0, 4))
            ->where('status', 'pending')->where('enrollment_expires_at', '>', now())->get()
            ->first(fn (Device $d) => $d->enrollment_code_hash && Hash::check($normalized, $d->enrollment_code_hash));
        if (! $device) {
            throw $generic;
        }
        if (strlen($publicKey) < 40 || strlen($publicKey) > 4096) {
            throw new DomainException('Invalid device public key.', 'invalid_public_key');
        }

        $actor = new Actor(id: null, name: "Device {$device->name}", source: 'api', tenantId: $device->tenant_id,
            deviceId: $device->id, ip: request()->ip());

        return $this->recorder->transaction(function () use ($device, $publicKey, $actor) {
            $device->status = 'active';
            $device->public_key = $publicKey;
            $device->enrolled_at = now();
            $device->enrollment_code_hash = null;
            $device->enrollment_lookup = null;
            $device->enrollment_expires_at = null;
            $this->recorder->save($actor, $device, 'device.enrolled', branchId: $device->branch_id);
            $token = $device->createToken('device', ['device'])->plainTextToken;

            return ['device_id' => $device->id, 'token' => $token, 'kind' => $device->kind, 'branch_id' => $device->branch_id, 'till_id' => $device->till_id];
        });
    }

    public function revoke(Actor $actor, Device $device, string $reason): void
    {
        $this->require($actor, $device->branch);
        if (trim($reason) === '') {
            throw new DomainException('Revoking a device needs a reason (e.g. lost, stolen, replaced).');
        }
        $this->recorder->transaction(function () use ($actor, $device, $reason) {
            $device->status = 'revoked';
            $device->revoked_at = now();
            $device->revoked_by = $actor->id;
            $device->revoke_reason = $reason;
            $device->enrollment_code_hash = null;
            $device->enrollment_lookup = null;
            $this->recorder->save($actor, $device, 'device.revoked', $reason, $device->branch_id);
            $device->tokens()->delete();
            // Staff sessions opened on this device die with it.
            DB::table('personal_access_tokens')->where('abilities', 'like', '%"device:'.$device->id.'"%')->delete();
        });
    }

    public static function series(int $i): string
    {
        $s = '';
        for ($n = $i; $n >= 0; $n = intdiv($n, 26) - 1) {
            $s = chr(65 + $n % 26).$s;
        }

        return $s;
    }

    private function issueCode(Actor $actor, Device $device): string
    {
        $code = strtoupper(Str::random(4).'-'.Str::random(4).'-'.Str::random(4));
        $code = strtr($code, ['0' => '8', 'O' => 'P', '1' => '7', 'I' => 'J', 'L' => 'M']); // unambiguous when read aloud
        $device->enrollment_code_hash = Hash::make(str_replace('-', '', $code));
        $device->enrollment_lookup = substr($code, 0, 4);
        $device->enrollment_expires_at = now()->addHours((int) $this->settings->get('security.device_enrollment_hours', $actor->tenantId));

        return $code;
    }

    private function require(Actor $actor, Branch $branch): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'admin.devices.manage', $branch)) {
            throw new AccessDenied;
        }
    }
}
