<?php

namespace App\Domain\Support;

use App\Domain\Sync\Replication;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The one way domain services change data: run the mutation, its audit row and its outbox
 * event in a single transaction. Any exception rolls back all three.
 */
final class Recorder
{
    public function __construct(public readonly AuditLog $audit, public readonly Outbox $outbox) {}

    /**
     * @template T
     *
     * @param  callable(): T  $mutation
     * @return T
     */
    public function transaction(callable $mutation): mixed
    {
        return DB::transaction($mutation, 3);
    }

    /**
     * Conflict policy "HQ wins" (spec §4.2), enforced at the source: on a branch server, master
     * data (company, people, catalog, pricing) only changes by sync from HQ, so it can never
     * conflict. Node-local operational changes — till sign-in counters, a person's own 2FA on this
     * node — are allowed; they are not sent back to HQ.
     */
    private function assertWritableHere(Actor $actor, Model $model, string $action): void
    {
        if (config('erp.node.role') !== 'branch' || $actor->source === 'sync') {
            return;
        }
        if (str_starts_with($action, 'pos.') || str_starts_with($action, 'auth.')) {
            return;
        }
        $group = Replication::groupOf(AuditLog::typeOf($model));
        if (in_array($group, Replication::MASTER_GROUPS, true)) {
            throw new DomainException('This is managed at head office. Make the change there; it reaches this branch at the next sync.', 'hq_only');
        }
    }

    /** Diff a model's pending dirty attributes as field => [before, after]. */
    public static function diff(Model $model, array $ignore = ['updated_at', 'created_at', 'version']): array
    {
        $changes = [];
        foreach ($model->getDirty() as $field => $after) {
            if (in_array($field, $ignore, true)) {
                continue;
            }
            $changes[$field] = [$model->exists ? $model->getOriginal($field) : null, $model->getAttribute($field)];
        }

        return $changes;
    }

    /** Save with audit + outbox; bumps the optimistic version on updates. */
    public function save(Actor $actor, Model $model, string $action, ?string $reason = null, ?string $branchId = null, array $payload = []): Model
    {
        $this->assertWritableHere($actor, $model, $action);

        return $this->transaction(function () use ($actor, $model, $action, $reason, $branchId, $payload) {
            $changes = self::diff($model);
            if ($model->exists && $changes === []) {
                return $model; // no-op saves never manufacture audit history
            }
            if ($model->exists) {
                $model->setAttribute('version', (int) $model->getOriginal('version') + 1);
            }
            $model->save();
            $this->audit->record($actor, $action, $model, $changes, $reason, $branchId);
            $this->outbox->publish($actor, $model, $action, ['changes' => array_keys($changes)] + $payload);

            return $model;
        });
    }
}
