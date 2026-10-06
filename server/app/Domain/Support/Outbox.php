<?php

namespace App\Domain\Support;

use App\Domain\Sync\Replication;
use App\Models\OutboxEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Transactional outbox: events are stored with the business change and delivered later by the
 * sync engine (and, from Phase 4, store connectors). Each event carries a full snapshot of the
 * record so any node can apply it idempotently. Delivery is at-least-once; receivers dedupe on
 * the event id.
 */
final class Outbox
{
    /** @param array $meta what happened, for people and connectors (the snapshot says what it is now) */
    public function publish(Actor $actor, Model $aggregate, string $eventType, array $meta = []): OutboxEvent
    {
        $type = AuditLog::typeOf($aggregate);

        return $this->publishRaw($actor, $type, (string) $aggregate->getKey(), (int) ($aggregate->getAttribute('version') ?? 1),
            $eventType, ['snapshot' => Replication::snapshot($aggregate), 'meta' => $meta]);
    }

    public function publishRaw(Actor $actor, string $aggregateType, string $aggregateId, int $version, string $eventType, array $payload): OutboxEvent
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Outbox events must be written inside the domain transaction.');
        }

        $event = new OutboxEvent;
        $event->forceFill([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $actor->tenantId,
            'origin_node' => (string) config('erp.node.id'),
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'aggregate_version' => $version,
            'event_type' => $eventType,
            'sync_group' => Replication::groupOf($aggregateType),
            'payload' => $payload,
            'correlation_id' => $actor->requestId,
            'occurred_at' => now(),
        ])->save();

        return $event;
    }
}
