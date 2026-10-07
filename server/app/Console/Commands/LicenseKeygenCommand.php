<?php

namespace App\Console\Commands;

use App\Domain\Licensing\Vendor\VendorKeys;
use Illuminate\Console\Command;

/** Synclytics only: create the signing key pair. The private file must be kept offline / on the licence server only. */
class LicenseKeygenCommand extends Command
{
    protected $signature = 'license:keygen {--kid= : key id, e.g. syn-2026-1} {--out= : where to write the PRIVATE key file (outside the application folder)}';

    protected $description = 'Synclytics only: generate a licence signing key pair';

    public function handle(): int
    {
        $kid = (string) ($this->option('kid') ?: 'syn-'.now()->format('Y').'-1');
        $out = (string) $this->option('out');
        if ($out === '' || file_exists($out)) {
            $this->error('Give --out with a new file path outside the application folder.');

            return self::FAILURE;
        }
        $dir = realpath(dirname($out));
        if ($dir === false || str_starts_with(strtolower($dir), strtolower(base_path()))) {
            $this->error('Refusing to write the private key inside the application folder (or into a folder that does not exist).');

            return self::FAILURE;
        }
        $k = VendorKeys::generate($kid);
        file_put_contents($out, json_encode(['kid' => $kid, 'secret' => $k['secret']], JSON_PRETTY_PRINT));
        @chmod($out, 0600);
        $this->info("Private key written to {$out}. Keep it secret and backed up offline: anyone holding it can issue licences.");
        $this->line('Add this line to config/license.php → public_keys:');
        $this->line("    '{$kid}' => '{$k['public']}',");

        return self::SUCCESS;
    }
}
