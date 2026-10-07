<?php

namespace App\Console\Commands;

use App\Domain\Licensing\LicenseManager;
use App\Domain\Licensing\LicenseService;
use Illuminate\Console\Command;

/** php artisan license:status — what this installation is licensed for, and its installation code. */
class LicenseStatusCommand extends Command
{
    protected $signature = 'license:status';

    protected $description = 'Show the licence state and this installation\'s code (to send to Synclytics)';

    public function handle(LicenseManager $license): int
    {
        $s = $license->status();
        $this->info("State: {$s['state']}".($s['message'] ? " — {$s['message']}" : ''));
        if ($s['license_id']) {
            $this->line("Licence {$s['short']} for {$s['customer']}, valid until {$s['expires_at']}");
        }
        $this->line('Installation code: '.LicenseService::installationCode());

        return self::SUCCESS;
    }
}
