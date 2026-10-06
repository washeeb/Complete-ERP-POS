<?php

namespace App\Domain\Sync;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Domain\Sync\Transport\SyncTransport;
use App\Models\SyncPeer;
use App\Security\Authorizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Pairing a branch server with head office. */
final class NodeSetup
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly SyncTransport $transport,
    ) {}

    /** At HQ: allow a branch server to connect. Returns the shared secret — shown once. */
    public function addBranchNode(Actor $actor, string $nodeId, string $name): array
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'sync.manage')) {
            throw new AccessDenied;
        }
        if (config('erp.node.role') === 'branch') {
            throw new DomainException('Branch servers are registered at head office.');
        }
        $nodeId = strtoupper(trim($nodeId));
        if (! preg_match('/^[A-Z0-9-]{2,40}$/', $nodeId) || $nodeId === config('erp.node.id')) {
            throw new DomainException('Node id: 2–40 letters, digits or dashes, different from this server’s own id.');
        }
        $secret = Str::random(48);
        $peer = new SyncPeer(['node_id' => $nodeId, 'name' => $name, 'role' => 'branch', 'secret' => $secret, 'is_active' => true]);
        $peer->tenant_id = $actor->tenantId;
        $peer->groups = array_fill_keys($peer->groupsWeReceive(), 'auto');
        $this->recorder->save($actor, $peer, 'sync.peer_added');

        return ['peer' => $peer, 'secret' => $secret, 'tenant_id' => $actor->tenantId, 'hq_node_id' => (string) config('erp.node.id')];
    }

    /** On a fresh branch server: connect to HQ, take the company identity, then sync everything down. */
    public function joinHq(string $baseUrl, string $hqNodeId, string $tenantId, string $secret): SyncPeer
    {
        if (config('erp.node.role') !== 'branch') {
            throw new DomainException('Set ERP_NODE_ROLE=branch on this server before joining head office.');
        }
        if (SyncPeer::withoutGlobalScopes()->where('role', 'hq')->exists()) {
            throw new DomainException('This server is already joined to head office.');
        }
        $peer = new SyncPeer(['node_id' => strtoupper($hqNodeId), 'name' => 'Head office', 'role' => 'hq', 'base_url' => $baseUrl,
            'secret' => $secret, 'is_active' => true]);
        $peer->tenant_id = $tenantId;
        $hello = $this->transport->hello($peer);
        if (($hello['tenant']['id'] ?? null) !== $tenantId || ($hello['node_id'] ?? null) !== $peer->node_id) {
            throw new DomainException('Head office answered with a different company or node id.');
        }

        return DB::transaction(function () use ($peer, $hello) {
            if (! DB::table('tenants')->where('id', $peer->tenant_id)->exists()) {
                DB::table('tenants')->insert($hello['tenant']); // the company identity; everything else arrives by sync
            }
            $peer->groups = array_fill_keys($peer->groupsWeReceive(), 'auto');
            $actor = Actor::system($peer->tenant_id, 'Node setup (php artisan erp:node:join)', 'cli');
            $this->recorder->save($actor, $peer, 'sync.joined');

            return $peer;
        });
    }
}
