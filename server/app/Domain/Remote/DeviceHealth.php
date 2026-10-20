<?php

namespace App\Domain\Remote;

use App\Domain\Messaging\Notifier;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use App\Security\Authorizer;
use Illuminate\Support\Facades\Cache;

/**
 * Device health and remote fixes (owner request 2026-10-20, phase 1B).
 *
 * The till sends a snapshot every few minutes; the latest is kept on the device. Problems are worked out from it
 * (offline during trading hours, sales stuck in the queue, storage nearly full, an old version, many errors) and IT
 * is told once a day per problem. A fix is queued in the back office by someone who may control devices (the paid
 * Remote access add-on) and is handed to the till with its next health answer; the till reports the result.
 */
final class DeviceHealth
{
    public const STALE_MINUTES = 20;      // no snapshot for this long while trading = offline

    public const STUCK_MINUTES = 30;      // the oldest unsent item is this old = stuck

    public const LOW_STORAGE_MB = 200;

    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly RemoteAccess $remote,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Keep the till's snapshot and hand over the fixes waiting for it (marked sent).
     *
     * @return list<array{id: string, command: string}>
     */
    public function record(Device $device, array $snapshot): array
    {
        $clean = array_intersect_key($snapshot, array_flip(['app', 'desktop', 'uptime_min', 'queue_pending', 'queue_review', 'oldest_pending_min', 'latency_ms',
            'connection', 'storage_used_mb', 'storage_free_mb', 'printer', 'catalog_age_min', 'errors_recent', 'screen']));
        $clean = array_map(fn ($v) => is_string($v) ? mb_substr($v, 0, 120) : (is_bool($v) || is_int($v) || is_float($v) || $v === null ? $v : null), $clean);
        $device->forceFill(['health' => $clean, 'health_at' => now()])->saveQuietly();

        $waiting = DeviceCommand::withoutGlobalScopes()->where('device_id', $device->id)->where('status', 'pending')->orderBy('created_at')->get();
        foreach ($waiting as $c) {
            $c->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
        }

        return $waiting->map(fn ($c) => ['id' => $c->id, 'command' => $c->command])->all();
    }

    /** The till reports what a fix did. */
    public function complete(Device $device, string $id, string $status, ?string $result): DeviceCommand
    {
        $c = DeviceCommand::withoutGlobalScopes()->where('device_id', $device->id)->find($id) ?? throw new DomainException('Unknown command.');
        if (in_array($c->status, ['done', 'failed', 'cancelled'], true)) {
            return $c;
        }
        $c->forceFill(['status' => $status === 'done' ? 'done' : 'failed', 'done_at' => now(), 'result' => $result === null ? null : mb_substr($result, 0, 20000)])->save();

        return $c;
    }

    /** Someone with control queues a fix for a device (needs the Remote access add-on and remote.control at its branch). */
    public function queue(Actor $actor, Device $device, string $command, ?string $reason = null): DeviceCommand
    {
        $device = Device::withoutGlobalScopes()->findOrFail($device->id); // as it is now (enrolled, revoked…)
        $branch = Branch::findOrFail($device->branch_id);
        if (! $actor->user || $this->remote->staffLevel($actor->user, $branch) !== 'control') {
            throw new AccessDenied;
        }
        if (! array_key_exists($command, DeviceCommand::COMMANDS)) {
            throw new DomainException('Choose what to do.');
        }
        if (! $device->isActive()) {
            throw new DomainException('This device is not active.');
        }

        return $this->recorder->transaction(function () use ($actor, $device, $command, $reason) {
            $c = (new DeviceCommand)->forceFill(['tenant_id' => $device->tenant_id, 'device_id' => $device->id, 'command' => $command, 'status' => 'pending',
                'requested_by' => $actor->id, 'requested_by_name' => $actor->name, 'reason' => $reason ? mb_substr($reason, 0, 255) : null]);
            $c->save();
            $this->recorder->audit->record($actor, 'device.fix_queued', $device, ['command' => [null, $command]], $reason ?: DeviceCommand::COMMANDS[$command], $device->branch_id);

            return $c;
        });
    }

    /**
     * What is wrong with the device now, in plain words (empty = fine). Offline counts only during the branch's trading
     * hours, so a till switched off at night is not a problem.
     *
     * @return list<string>
     */
    public function problems(Device $device): array
    {
        if (! $device->isActive() || $device->kind === 'kds') {
            return [];
        }
        $branch = Branch::find($device->branch_id);
        $h = $device->health ?? [];
        $out = [];
        $trading = $branch && $this->remote->trading($branch);
        if ($device->health_at === null || $device->health_at->lt(now()->subMinutes(self::STALE_MINUTES))) {
            if ($trading && $device->health_at !== null) {
                $out[] = 'not heard from since '.$device->health_at->diffForHumans();
            }

            return $out; // the rest of an old snapshot says nothing about now
        }
        if (($h['oldest_pending_min'] ?? 0) >= self::STUCK_MINUTES) {
            $out[] = ($h['queue_pending'] ?? 0).' item(s) waiting to be sent for '.$h['oldest_pending_min'].' min';
        }
        if (($h['queue_review'] ?? 0) > 0) {
            $out[] = $h['queue_review'].' item(s) refused by the server';
        }
        if (($h['storage_free_mb'] ?? null) !== null && $h['storage_free_mb'] < self::LOW_STORAGE_MB) {
            $out[] = "storage nearly full ({$h['storage_free_mb']} MB free)";
        }
        if (($h['app'] ?? null) && ($h['app'] ?? '') !== 'dev' && config('erp.version') && ($h['app'] ?? '') !== config('erp.version')) {
            $out[] = "till version {$h['app']}, server ".config('erp.version').': close and reopen the till';
        }
        if (($h['errors_recent'] ?? 0) >= 5) {
            $out[] = "{$h['errors_recent']} recent errors";
        }

        return $out;
    }

    /** Tell IT (admin.devices.manage) about devices with problems, once a day per device and problem. */
    public function alert(string $tenantId): int
    {
        $n = 0;
        foreach (Device::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('status', 'active')->get() as $d) {
            foreach ($this->problems($d) as $p) {
                $key = 'device-alert:'.$d->id.':'.md5(preg_replace('/\d+/', '#', $p)).':'.now()->toDateString();
                if (Cache::add($key, 1, now()->endOfDay())) {
                    $n += $this->notifier->toPermission($tenantId, 'admin.devices.manage', $d->branch_id, "{$d->name}: {$p}", 'System → Devices shows its health; a fix can be sent from there.');
                }
            }
        }

        return $n;
    }

    /** People who may queue fixes on the device's branch. */
    public function canFix(User $user, Device $device): bool
    {
        $branch = Branch::find($device->branch_id);

        return $branch !== null && $this->remote->staffLevel($user, $branch) === 'control';
    }
}
