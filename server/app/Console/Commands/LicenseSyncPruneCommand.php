<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Vendor only: forget used nonces and old push answers (the change feed itself is kept). */
class LicenseSyncPruneCommand extends Command
{
    protected $signature = 'license:sync-prune';

    protected $description = 'Vendor only: remove used sync nonces (older than 1 day) and push answers (older than 30 days)';

    public function handle(): int
    {
        if (! config('license.server.enabled')) {
            return self::SUCCESS;
        }
        DB::table('vendor_sync_nonces')->where('created_at', '<', now()->subDay())->delete();
        DB::table('vendor_sync_requests')->where('created_at', '<', now()->subDays(30))->delete();

        return self::SUCCESS;
    }
}
