<?php

namespace App\Console\Commands;

use App\Domain\Imports\ImportService;
use App\Models\DataImport;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Large files imported in the background (owner 2026-11-07: 2,467 products took longer than a web request may run).
 * Started by the scheduler every minute, and straight after the upload where the server allows it. One at a time.
 */
class ImportsCommand extends Command
{
    protected $signature = 'erp:imports';

    protected $description = 'Run the large imports waiting in the background, one at a time';

    public function handle(ImportService $imports, TenantContext $tenants): int
    {
        $lock = Cache::lock('erp-imports', 3 * 3600);
        if (! $lock->get()) {
            return self::SUCCESS; // another run is working through them
        }
        try {
            ImportService::abandonStale();
            while ($next = DataImport::withoutGlobalScopes()->where('status', 'queued')->orderBy('created_at')->first()) {
                $tenants->set($next->tenant_id);
                $done = $imports->runQueued($next);
                $this->line("{$done->file_name}: {$done->status}, {$done->rows_ok} of {$done->rows_total} row(s)");
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
