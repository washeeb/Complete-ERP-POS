<?php

namespace App\Domain\Pos;

use App\Domain\Devices\DeviceSignature;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Device;
use App\Security\Authorizer;

/**
 * A till's unsent queue saved to a file ("Export unsent sales" on the till) and loaded here —
 * for a till being reinstalled, replaced or revoked before it could sync. The file is signed by
 * the till's own key, so it is verified even if the device has since been revoked; every event
 * still goes through the normal offline checks and review flags.
 */
final class TillFileImport
{
    public function __construct(
        private readonly DeviceSignature $signature,
        private readonly PosSyncService $sync,
        private readonly Authorizer $authz,
    ) {}

    /** @return array{device: Device, results: list<array>} */
    public function import(Actor $actor, string $content): array
    {
        $file = json_decode($content, true);
        if (! is_array($file) || ($file['format'] ?? null) !== 'synclytics-till-queue-1' || ! isset($file['device_id'], $file['body'], $file['signature'])) {
            throw new DomainException('This is not a till queue file.', 'malformed');
        }
        $device = Device::where('tenant_id', $actor->tenantId)->find($file['device_id'])
            ?? throw new DomainException('That till is not registered in this company.', 'malformed');
        if (! $actor->user || ! $this->authz->allows($actor->user, 'sync.manage', $device->branch)) {
            throw new AccessDenied;
        }
        $this->signature->verify($device, (string) $file['body'], (string) $file['signature']);
        $events = json_decode((string) $file['body'], true)['events'] ?? null;
        if (! is_array($events)) {
            throw new DomainException('The till file has no queued work.', 'malformed');
        }

        return ['device' => $device, 'results' => $this->sync->apply($device, $events)];
    }
}
