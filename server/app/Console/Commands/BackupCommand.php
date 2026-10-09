<?php

namespace App\Console\Commands;

use App\Domain\Backup\BackupService;
use App\Domain\Support\DomainException;
use Illuminate\Console\Command;

/** Encrypted backup now (also run every night by the scheduler), then a check of the newest backup. */
class BackupCommand extends Command
{
    protected $signature = 'erp:backup {--verify : Check the newest backup instead (decrypt, read, and a restore drill when allowed)} {--no-drill : With --verify, skip the restore drill}';

    protected $description = 'Make an encrypted backup and copy it to every destination (or --verify the newest one)';

    public function handle(BackupService $backups): int
    {
        try {
            if ($this->option('verify')) {
                $run = $backups->verifyLatest(! $this->option('no-drill'));
                $this->line("{$run->file_name}: {$run->verify_result}");

                return str_starts_with((string) $run->verify_result, 'FAILED') ? self::FAILURE : self::SUCCESS;
            }
            $run = $backups->run('Command line / scheduler');
            $this->info("{$run->file_name}: ".number_format($run->size_bytes / 1048576, 1).' MB, '.$run->status.($run->error ? " ({$run->error})" : ''));
            foreach ($run->destinations as $d) {
                $this->line(($d['ok'] ? '  ✓ ' : '  ✗ ').$d['name'].($d['error'] ? ": {$d['error']}" : ''));
            }

            return $run->status === 'ok' ? self::SUCCESS : self::FAILURE;
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
