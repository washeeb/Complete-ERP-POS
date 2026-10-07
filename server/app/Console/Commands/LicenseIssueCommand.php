<?php

namespace App\Console\Commands;

use App\Domain\Licensing\LicenseService;
use App\Domain\Licensing\Vendor\LicenseIssuer;
use App\Domain\Licensing\Vendor\VendorKeys;
use App\Domain\Support\Actor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Synclytics only: issue a licence key for an installation code. */
class LicenseIssueCommand extends Command
{
    protected $signature = 'license:issue
        {--customer= : customer name}
        {--code= : the installation code (SYNREQ-…) the customer sent}
        {--until= : last day the licence is valid (YYYY-MM-DD)}
        {--branches= : maximum branches (blank = unlimited)}
        {--devices= : maximum tills and devices}
        {--users= : maximum active users}
        {--features= : comma list of finance,hr (blank = all)}
        {--modules= : comma list of industry modules (blank = all)}
        {--grace=14 : grace days after expiry}
        {--checkin=30 : days the installation may go without reaching the licence server (0 = no check-in)}
        {--server= : licence server URL for check-ins}
        {--replaces= : licence id this one renews (handed over at the old one\'s next check-in)}
        {--key= : path to the private key file (default LICENSE_PRIVATE_KEY_PATH)}
        {--activate : development only — also activate it on this installation}';

    protected $description = 'Synclytics only: issue a signed licence key';

    public function handle(): int
    {
        $list = fn (?string $v) => $v === null || $v === '' ? null : array_values(array_filter(array_map('trim', explode(',', $v))));
        $int = fn (?string $v) => $v === null || $v === '' ? null : (int) $v;
        $keys = VendorKeys::load($this->option('key'));
        $code = $this->option('code') ?: ($this->option('activate') ? LicenseService::installationCode() : null);
        if (! $code || ! $this->option('customer') || ! $this->option('until')) {
            $this->error('Give --customer, --code and --until.');

            return self::FAILURE;
        }
        $issued = LicenseIssuer::issue(['customer' => $this->option('customer'), 'request_code' => $code, 'expires_at' => $this->option('until'),
            'branches' => $int($this->option('branches')), 'devices' => $int($this->option('devices')), 'users' => $int($this->option('users')),
            'features' => $list($this->option('features')), 'modules' => $list($this->option('modules')), 'grace_days' => (int) $this->option('grace'),
            'checkin_days' => (int) $this->option('checkin') ?: null, 'server_url' => $this->option('server') ?: null, 'dev' => $keys['kid'] === 'dev'], $keys);
        if ($old = $this->option('replaces')) {
            DB::table('vendor_licenses')->where('id', $old)->update(['superseded_by' => $issued['license_id'], 'updated_at' => now()]);
        }
        $this->info("Licence {$issued['license_id']} for {$this->option('customer')} until {$this->option('until')}:");
        $this->line($issued['key']);
        if ($this->option('activate')) {
            app(LicenseService::class)->activate(Actor::system((string) DB::table('tenants')->value('id'), 'Licence (development)', 'system'), $issued['key']);
            $this->info('Activated on this installation.');
        }

        return self::SUCCESS;
    }
}
