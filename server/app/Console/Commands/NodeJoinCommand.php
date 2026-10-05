<?php

namespace App\Console\Commands;

use App\Domain\Support\DomainException;
use App\Domain\Sync\NodeSetup;
use App\Domain\Sync\SyncEngine;
use App\Support\TenantContext;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;

/** php artisan erp:node:join https://hq.example.com HQ <tenant-id>   (run on a fresh branch server) */
class NodeJoinCommand extends Command
{
    protected $signature = 'erp:node:join {url} {hq_node_id} {tenant_id}';

    protected $description = 'On a branch server: connect to head office and download everything this branch needs';

    public function handle(NodeSetup $setup, SyncEngine $engine): int
    {
        $secret = password('Secret from head office', required: true);
        try {
            $peer = $setup->joinHq($this->argument('url'), $this->argument('hq_node_id'), $this->argument('tenant_id'), $secret);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        app(TenantContext::class)->set($peer->tenant_id);
        $this->info('Joined. Downloading company data…');
        $stats = $engine->run($peer, 600);
        $this->line("Received {$stats['pulled']} changes ({$stats['applied']} applied, {$stats['failed']} to retry).".($stats['error'] ? " Last error: {$stats['error']}" : ''));

        return $stats['error'] ? self::FAILURE : self::SUCCESS;
    }
}
