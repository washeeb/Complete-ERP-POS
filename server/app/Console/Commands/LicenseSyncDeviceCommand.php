<?php

namespace App\Console\Commands;

use App\Domain\Licensing\Vendor\SyncDevices;
use App\Domain\Support\Actor;
use App\Models\Tenant;
use App\Models\Vendor\VendorSyncDevice;
use Illuminate\Console\Command;

/** Vendor only: allow a copy of the SES Licensing app to sync, or stop one (the same as /vendor → Sync devices). */
class LicenseSyncDeviceCommand extends Command
{
    protected $signature = 'license:sync-device {name? : name for a new device} {--revoke= : id of a device to stop} {--list : show the devices}';

    protected $description = 'Vendor only: create a one-time code for the SES Licensing app, list or revoke devices';

    public function handle(SyncDevices $devices): int
    {
        if ($this->option('list')) {
            $this->table(['Id', 'Name', 'Status', 'Last seen'], VendorSyncDevice::orderBy('created_at')->get()->map(fn ($d) => [$d->id, $d->name, $d->status, $d->last_seen_at?->toDateTimeString() ?? '—']));

            return self::SUCCESS;
        }
        $actor = Actor::system((string) Tenant::query()->orderBy('created_at')->value('id'), 'Console', 'cli');
        if ($id = $this->option('revoke')) {
            $devices->revoke($actor, VendorSyncDevice::findOrFail($id));
            $this->info('Revoked.');

            return self::SUCCESS;
        }
        $r = $devices->create($actor, (string) $this->argument('name'));
        $this->info('Type this code in the app (Sync → Connect). It works once, for '.SyncDevices::CODE_MINUTES.' minutes:');
        $this->line($r['code']);

        return self::SUCCESS;
    }
}
