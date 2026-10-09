<?php

namespace App\Console\Commands;

use App\Domain\Licensing\LicenseService;
use Illuminate\Console\Command;

/** Daily: confirm the licence with the licence server (head office). */
class LicenseCheckinCommand extends Command
{
    protected $signature = 'license:checkin';

    protected $description = 'Check in with the licence server (runs daily at head office)';

    public function handle(LicenseService $licenses): int
    {
        $r = $licenses->checkIn();
        $r['ok'] ? $this->info($r['message']) : $this->warn($r['message']);

        return self::SUCCESS;
    }
}
