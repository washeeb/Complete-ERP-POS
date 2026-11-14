<?php

namespace App\Console\Commands;

use App\Domain\Access\AccessRequests;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Hourly: access requests nobody answered in 72 hours lapse, and the person who asked is told. */
class AccessRequestsCommand extends Command
{
    protected $signature = 'erp:access-requests';

    protected $description = 'Let access requests not answered in 72 hours lapse';

    public function handle(AccessRequests $requests): int
    {
        $n = 0;
        foreach (DB::table('tenants')->pluck('id') as $tenant) {
            $n += $requests->expire($tenant);
        }
        $this->info("{$n} request(s) lapsed.");

        return self::SUCCESS;
    }
}
