<?php

namespace App\Console\Commands;

use App\Domain\Sync\SyncEngine;
use App\Models\SyncPeer;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/** Scheduled every minute; also "Sync now" in the Sync Center. */
class SyncRunCommand extends Command
{
    protected $signature = 'erp:sync {--peer= : Node id of one peer}';

    protected $description = 'Exchange changes with other nodes (push ours, pull theirs)';

    public function handle(SyncEngine $engine): int
    {
        $peers = SyncPeer::withoutGlobalScopes()->where('is_active', true)->whereNotNull('base_url')
            ->when($this->option('peer'), fn ($q, $id) => $q->where('node_id', strtoupper($id)))->get();
        $ok = true;
        foreach ($peers as $peer) {
            app(TenantContext::class)->set($peer->tenant_id);
            $s = $engine->run($peer);
            $this->line("{$peer->name}: pushed {$s['pushed']}, received {$s['pulled']} (applied {$s['applied']}, staged {$s['staged']}, failed {$s['failed']})"
                .($s['error'] ? " — ERROR: {$s['error']}" : ''));
            $ok = $ok && ! $s['error'];
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
