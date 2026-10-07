<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Synclytics only: withdraw a licence; the installation becomes read-only at its next check-in. */
class LicenseRevokeCommand extends Command
{
    protected $signature = 'license:revoke {id : licence id} {reason : shown to the customer}';

    protected $description = 'Synclytics only: revoke a licence (takes effect at the installation\'s next check-in)';

    public function handle(): int
    {
        $n = DB::table('vendor_licenses')->where('id', $this->argument('id'))->update(['revoked_at' => now(), 'revoked_reason' => mb_substr($this->argument('reason'), 0, 255), 'updated_at' => now()]);
        $n ? $this->info('Revoked.') : $this->error('No such licence.');

        return $n ? self::SUCCESS : self::FAILURE;
    }
}
