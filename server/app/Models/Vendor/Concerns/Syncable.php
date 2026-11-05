<?php

namespace App\Models\Vendor\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * A record shared with the SES Licensing desktop app: its version goes up when a field the app sees changes, and the
 * change goes in the feed pulls read. Anything saved through the model (the /vendor screens, renewals, a push) counts;
 * check-in bookkeeping (last seen, health) does not, it is not a shared field.
 */
trait Syncable
{
    protected static function bootSyncable(): void
    {
        static::creating(function ($m) {
            $m->version = 1;
        });
        static::updating(function ($m) {
            if (array_intersect(array_keys($m->getDirty()), static::SYNC_FIELDS)) {
                $m->version = (int) $m->getOriginal('version') + 1;
            }
        });
        static::saved(function ($m) {
            if ($m->wasRecentlyCreated || $m->wasChanged('version')) {
                self::noteSync((string) $m->getKey(), (int) $m->version);
            }
        });
        static::deleted(fn ($m) => self::noteSync((string) $m->getKey(), (int) $m->version + 1, true));
    }

    /**
     * Into the feed. The lock (held to the end of the transaction) keeps cursor numbers in commit order: without it a
     * pull could pass a number whose transaction has not committed yet and never see that change.
     */
    public static function noteSync(string $id, int $version, bool $deleted = false): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('select pg_advisory_xact_lock(?)', [720_260_001]);
        }
        DB::table('vendor_sync_changes')->insert(['entity' => static::SYNC_ENTITY, 'record_id' => $id, 'version' => $version, 'deleted' => $deleted, 'created_at' => now()]);
    }
}
