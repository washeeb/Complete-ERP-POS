<?php

namespace App\Domain\Sync;

use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\OutboxEvent;
use App\Models\SyncInboxEvent;
use App\Models\SyncPeer;
use Illuminate\Support\Facades\DB;

/**
 * Both halves of the exchange, used by the HTTP endpoints, the in-process test transport and
 * file import alike: receive events (store once, apply or stage by group mode) and serve our own
 * events after a cursor.
 */
final class SyncServer
{
    public function __construct(private readonly ReplicationApplier $applier, private readonly ReviewQueue $reviews) {}

    /**
     * Store and (by mode) apply events from a peer, in their origin order.
     *
     * @return array{acked_seq: int, results: list<array{id: string, status: string, error?: string}>}
     */
    public function receive(SyncPeer $peer, array $events, bool $forceApply = false): array
    {
        usort($events, fn ($a, $b) => $a['seq'] <=> $b['seq']);
        $acked = 0;
        $results = [];
        $allowed = $peer->groupsWeReceive();
        foreach ($events as $e) {
            if ($e['origin_node'] !== $peer->node_id || $e['tenant_id'] !== $peer->tenant_id) {
                $results[] = ['id' => $e['id'], 'status' => 'rejected', 'error' => 'Event does not come from this peer.'];

                continue;
            }
            $group = $e['sync_group'] ?? Replication::groupOf($e['aggregate_type']);
            $existing = SyncInboxEvent::withoutGlobalScopes()->find($e['id']);
            if (! $existing) {
                $mode = in_array($group, $allowed, true) ? ($forceApply ? 'auto' : $peer->modeFor($group)) : 'reject';
                $existing = new SyncInboxEvent;
                $existing->forceFill([
                    'id' => $e['id'], 'tenant_id' => $peer->tenant_id, 'peer_id' => $peer->id, 'origin_node' => $e['origin_node'],
                    'origin_seq' => $e['seq'], 'sync_group' => $group, 'aggregate_type' => $e['aggregate_type'],
                    'aggregate_id' => $e['aggregate_id'], 'event_type' => $e['event_type'], 'payload' => $e['payload'],
                    'status' => match ($mode) {
                        'auto' => 'pending', 'manual' => 'staged', 'off' => 'skipped', default => 'rejected'
                    },
                    'last_error' => $mode === 'reject' ? "This node does not accept “{$group}” from a {$peer->role} node." : null,
                    'occurred_at' => $e['occurred_at'], 'received_at' => now(),
                ])->save();
            }
            if (in_array($existing->status, ['pending', 'failed'], true)) {
                $this->applyStored($existing);
            }
            $results[] = ['id' => $e['id'], 'status' => $existing->status] + ($existing->last_error ? ['error' => $existing->last_error] : []);
            $acked = max($acked, (int) $e['seq']); // received and stored durably = acknowledged, even if applying must be retried
        }

        return ['acked_seq' => $acked, 'results' => $results];
    }

    /** Apply one stored event in its own transaction; failures are recorded and retried later. */
    public function applyStored(SyncInboxEvent $in): bool
    {
        try {
            DB::transaction(function () use ($in) {
                $this->applier->apply($in->getAttributes() + ['payload' => $in->payload]);
                $in->forceFill(['status' => 'applied', 'applied_at' => now(), 'last_error' => null])->save();
                if ($in->attempts > 0) {
                    $this->reviews->closeBySystem('sync_event', $in->id, 'Applied automatically on a later sync');
                }
            });

            return true;
        } catch (\Throwable $e) {
            $in->forceFill(['status' => 'failed', 'attempts' => $in->attempts + 1,
                'last_error' => $e instanceof DomainException ? $e->getMessage() : class_basename($e).': '.mb_substr($e->getMessage(), 0, 500)])->save();
            if ($in->attempts === 1) {
                DB::transaction(fn () => $this->reviews->add(Actor::system($in->tenant_id, 'Sync from '.$in->origin_node, 'sync'), 'sync_failed',
                    'sync_event', $in->id, "Change from {$in->origin_node} ({$in->event_type}) could not be applied: ".mb_substr((string) $in->last_error, 0, 150),
                    ['aggregate_type' => $in->aggregate_type, 'aggregate_id' => $in->aggregate_id]));
            }

            return false;
        }
    }

    /** Retry everything that failed earlier, oldest first (e.g. after the missing parent arrived). */
    public function retryFailed(SyncPeer $peer): int
    {
        $ok = 0;
        foreach (SyncInboxEvent::withoutGlobalScopes()->where('peer_id', $peer->id)->where('status', 'failed')->orderBy('origin_seq')->get() as $in) {
            $ok += $this->applyStored($in) ? 1 : 0;
        }

        return $ok;
    }

    /**
     * Our own events the peer may receive, after its cursor.
     *
     * @return array{events: list<array>, last_seq: int, has_more: bool}
     */
    public function serve(SyncPeer $peer, int $after, int $limit = 500): array
    {
        $limit = max(1, min($limit, 1000));
        $events = OutboxEvent::withoutGlobalScopes()
            ->where('tenant_id', $peer->tenant_id)
            ->where('origin_node', (string) config('erp.node.id'))
            ->where('seq', '>', $after)
            ->whereIn('sync_group', $peer->groupsWeSend())
            ->orderBy('seq')->limit($limit + 1)->get();
        $hasMore = $events->count() > $limit;
        $events = $events->take($limit);

        return [
            'events' => $events->map(fn (OutboxEvent $e) => self::wire($e))->values()->all(),
            // Advance past events of groups this peer doesn't get, so cursors keep moving.
            'last_seq' => $hasMore ? (int) $events->last()->seq : (int) (OutboxEvent::withoutGlobalScopes()->where('tenant_id', $peer->tenant_id)
                ->where('origin_node', (string) config('erp.node.id'))->max('seq') ?? $after),
            'has_more' => $hasMore,
        ];
    }

    public static function wire(OutboxEvent $e): array
    {
        return [
            'id' => $e->id, 'seq' => (int) $e->seq, 'tenant_id' => $e->tenant_id, 'origin_node' => $e->origin_node,
            'aggregate_type' => $e->aggregate_type, 'aggregate_id' => $e->aggregate_id, 'aggregate_version' => $e->aggregate_version,
            'event_type' => $e->event_type, 'sync_group' => $e->sync_group, 'payload' => $e->payload,
            'occurred_at' => $e->occurred_at->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    public function hello(SyncPeer $peer): array
    {
        return [
            'node_id' => (string) config('erp.node.id'),
            'role' => (string) config('erp.node.role'),
            'tenant' => (array) DB::table('tenants')->where('id', $peer->tenant_id)->first(),
            'server_time' => now()->toIso8601String(),
        ];
    }
}
