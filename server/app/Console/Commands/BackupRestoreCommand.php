<?php

namespace App\Console\Commands;

use App\Domain\Backup\BackupService;
use App\Domain\Support\DomainException;
use Illuminate\Console\Command;

/** Put a backup back. Replaces the live database — only for disaster recovery or moving to a new server. */
class BackupRestoreCommand extends Command
{
    protected $signature = 'erp:backup:restore {file : The .synbak file} {--force : Do not ask for confirmation}';

    protected $description = 'Restore an encrypted backup into this server (replaces the current data)';

    public function handle(BackupService $backups): int
    {
        $file = (string) $this->argument('file');
        try {
            $info = $backups->verify($file);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $m = $info['manifest'];
        $this->line("Backup of {$m['database']} on {$m['node']}, made {$m['created_at']}.");
        if (! $this->option('force') && ! $this->confirm('This REPLACES all current data with the backup. Continue?')) {
            return self::FAILURE;
        }
        $backups->restore($file);
        $this->info('Restored. The backup\'s settings were saved as .env.from-backup: if this is a new server, copy APP_KEY and ERP_AUDIT_KEY from it into .env, then run php artisan optimize.');

        return self::SUCCESS;
    }
}
