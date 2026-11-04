<?php

namespace App\Domain\Licensing\Vendor;

use App\Domain\Support\Actor;
use App\Domain\Support\AuditLog;
use App\Domain\Support\DomainException;
use App\Models\Vendor\VendorSyncDevice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Who may sync: staff create a device and get a one-time code (15 minutes); the app sends the code with its own public
 * key once, and from then on signs every request with the private half, which never leaves the app's vault.
 */
final class SyncDevices
{
    public const CODE_MINUTES = 15;

    public function __construct(private readonly AuditLog $audit) {}

    /** @return array{device: VendorSyncDevice, code: string} the code is shown once and only its hash is stored */
    public function create(Actor $actor, string $name): array
    {
        $name = mb_substr(trim($name), 0, 80);
        if ($name === '') {
            throw new DomainException('Name the device, for example "Owner\'s PC".');
        }
        $code = 'SYNC-'.strtoupper(Str::random(8)).'-'.strtoupper(Str::random(8)).'-'.strtoupper(Str::random(8));

        return DB::transaction(function () use ($actor, $name, $code) {
            $device = VendorSyncDevice::create(['tenant_id' => $actor->tenantId, 'name' => $name, 'status' => 'pending', 'enrol_hash' => hash('sha256', $code),
                'enrol_expires_at' => now()->addMinutes(self::CODE_MINUTES), 'created_by' => $actor->name]);
            $this->audit->record($actor, 'vendor.sync.device_created', $device, ['name' => [null, $name]]);

            return ['device' => $device, 'code' => $code];
        });
    }

    /** The app presents its code and public key. The code works once. */
    public function register(string $code, string $name, string $publicKey, ?string $ip): VendorSyncDevice
    {
        $key = base64_decode($publicKey, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new DomainException('The device key is not valid.');
        }

        return DB::transaction(function () use ($code, $name, $publicKey, $ip) {
            $device = VendorSyncDevice::where('enrol_hash', hash('sha256', trim($code)))->where('status', 'pending')->where('enrol_expires_at', '>', now())->lockForUpdate()->first()
                ?? throw new DomainException('That code is wrong, used or expired. Create a new one on the licence server.');
            $device->forceFill(['status' => 'active', 'public_key' => $publicKey, 'enrol_hash' => null, 'enrol_expires_at' => null, 'registered_at' => now(),
                'last_seen_at' => now(), 'last_ip' => $ip, 'name' => mb_substr(trim($name), 0, 80) ?: $device->name])->save();
            $this->audit->record(self::actor($device), 'vendor.sync.device_registered', $device, ['status' => ['pending', 'active']]);

            return $device;
        });
    }

    public function revoke(Actor $actor, VendorSyncDevice $device): VendorSyncDevice
    {
        if ($device->status === 'revoked') {
            throw new DomainException('Already revoked.');
        }

        return DB::transaction(function () use ($actor, $device) {
            $before = $device->status;
            $device->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'enrol_hash' => null, 'enrol_expires_at' => null])->save();
            $this->audit->record($actor, 'vendor.sync.device_revoked', $device, ['status' => [$before, 'revoked']]);

            return $device;
        });
    }

    /** The audit identity of a device: a named service, never a person. */
    public static function actor(VendorSyncDevice $device): Actor
    {
        return Actor::system($device->tenant_id, 'Sync device: '.$device->name, 'sync');
    }
}
