<?php

namespace App\Domain\Support;

use App\Models\OutboxEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Transactional outbox: events are stored with the business change and delivered later
 * (sync engine Phase 3, store connectors Phase 4). Delivery is at-least-once; receivers
 * dedupe on the event id.
 */
final class Outbox
{
    public function publish(Actor $actor, Model $aggregate, string $eventType, array $payload): OutboxEvent
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Outbox events must be written inside the domain transaction.');
        }

        $event = new OutboxEvent;
        $event->forceFill([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $actor->tenantId,
            'origin_node' => (string) config('erp.node.id'),
            'aggregate_type' => AuditLog::typeOf($aggregate),
            'aggregate_id' => $aggregate->getKey(),
            'aggregate_version' => (int) ($aggregate->getAttribute('version') ?? 1),
            'event_type' => $eventType,
            'payload' => $payload,
            'correlation_id' => $actor->requestId,
            'occurred_at' => now(),
        ])->save();

        return $event;
    }
}
