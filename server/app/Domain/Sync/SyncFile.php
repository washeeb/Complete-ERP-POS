<?php

namespace App\Domain\Sync;

use App\Domain\Support\DomainException;
use App\Models\OutboxEvent;
use App\Models\SyncPeer;

/**
 * USB / e-mail fallback for very poor links (spec §4.2): the same events the network would carry,
 * in a gzip file signed with the peer secret. Importing is as safe as receiving them online —
 * every event is applied once, whatever order or how many times files are imported.
 */
final class SyncFile
{
    public function __construct(private readonly SyncServer $server) {}

    /** Our events for this peer after $after (default: what it has acknowledged). */
    public function export(SyncPeer $peer, ?int $after = null): string
    {
        $after ??= $peer->role === 'hq' ? $peer->last_pushed_seq : 0;
        $events = OutboxEvent::withoutGlobalScopes()->where('tenant_id', $peer->tenant_id)
            ->where('origin_node', (string) config('erp.node.id'))->where('seq', '>', $after)
            ->whereIn('sync_group', $peer->groupsWeSend())->orderBy('seq')->limit(200_000)->get()
            ->map(fn ($e) => SyncServer::wire($e))->all();
        $header = ['format' => 1, 'tenant_id' => $peer->tenant_id, 'from_node' => (string) config('erp.node.id'),
            'to_node' => $peer->node_id, 'created_at' => now()->toIso8601String(), 'count' => count($events)];

        return gzencode(json_encode(['header' => $header, 'events' => $events,
            'sig' => SyncSigner::signFile($peer->secret, $events, $header)]), 9);
    }

    /** @return array{peer: SyncPeer, received: int, applied: int, staged: int, failed: int} */
    public function import(string $content): array
    {
        $data = json_decode((string) @gzdecode($content), true);
        $header = $data['header'] ?? null;
        if (! is_array($header) || ($header['format'] ?? null) !== 1 || ! is_array($data['events'] ?? null)) {
            throw new DomainException('This is not a sync file.', 'malformed');
        }
        if ($header['to_node'] !== (string) config('erp.node.id')) {
            throw new DomainException("This file is for node {$header['to_node']}, not this server.", 'malformed');
        }
        $peer = SyncPeer::withoutGlobalScopes()->where('tenant_id', $header['tenant_id'])->where('node_id', $header['from_node'])->first()
            ?? throw new DomainException("Node {$header['from_node']} is not paired with this server.", 'malformed');
        if (! hash_equals(SyncSigner::signFile($peer->secret, $data['events'], $header), (string) ($data['sig'] ?? ''))) {
            throw new DomainException('The file signature does not match; it may have been altered.', 'bad_signature');
        }
        $r = $this->server->receive($peer, $data['events']);
        $count = fn ($s) => count(array_filter($r['results'], fn ($x) => $x['status'] === $s));
        if ($peer->role === 'hq') {
            $peer->forceFill(['last_pulled_seq' => max($peer->last_pulled_seq, $r['acked_seq'])])->save();
        }

        return ['peer' => $peer, 'received' => count($data['events']), 'applied' => $count('applied'), 'staged' => $count('staged'), 'failed' => $count('failed')];
    }
}
