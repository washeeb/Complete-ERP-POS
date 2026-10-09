<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Creates the backup passphrase in .env once (never overwrites one). */
class BackupKeyCommand extends Command
{
    protected $signature = 'erp:backup:key';

    protected $description = 'Create the backup passphrase (BACKUP_PASSPHRASE in .env) if there is none';

    public function handle(): int
    {
        $env = base_path('.env');
        $content = is_file($env) ? (string) file_get_contents($env) : '';
        if (preg_match('/^BACKUP_PASSPHRASE=(.{12,})$/m', $content)) {
            $this->info('A backup passphrase is already set. It is not shown again; keep your printed copy safe.');

            return self::SUCCESS;
        }
        $words = implode('-', array_map(fn () => Str::lower(Str::random(5)), range(1, 6)));
        $content = preg_match('/^BACKUP_PASSPHRASE=.*$/m', $content)
            ? preg_replace('/^BACKUP_PASSPHRASE=.*$/m', "BACKUP_PASSPHRASE={$words}", $content)
            : rtrim($content)."\nBACKUP_PASSPHRASE={$words}\n";
        file_put_contents($env, $content);
        $this->warn('Backup passphrase (write it down and keep it away from the server — without it no backup can be restored):');
        $this->line("  {$words}");
        $this->call('config:clear');

        return self::SUCCESS;
    }
}
