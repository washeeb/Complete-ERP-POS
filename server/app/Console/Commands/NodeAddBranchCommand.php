<?php

namespace App\Console\Commands;

use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Sync\NodeSetup;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/** php artisan erp:node:add-branch B01 "Branch A server" --as=it@company.com   (run at HQ) */
class NodeAddBranchCommand extends Command
{
    protected $signature = 'erp:node:add-branch {node_id} {name} {--as= : Email of the administrator doing this}';

    protected $description = 'At head office: allow a branch server to sync, and print its connection details (shown once)';

    public function handle(NodeSetup $setup): int
    {
        $user = User::where('email', strtolower((string) $this->option('as')))->first();
        if (! $user) {
            $this->error('Pass --as=<email> of an administrator with sync rights.');

            return self::FAILURE;
        }
        app(TenantContext::class)->set($user->tenant_id);
        try {
            $r = $setup->addBranchNode(new Actor($user->id, $user->name, 'cli', $user->tenant_id, user: $user), $this->argument('node_id'), $this->argument('name'));
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Branch server registered. On the branch server run:');
        $this->line("  php artisan erp:node:join <this server's URL> {$r['hq_node_id']} {$r['tenant_id']}");
        $this->line("and paste this secret when asked (shown once): {$r['secret']}");

        return self::SUCCESS;
    }
}
