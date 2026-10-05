<?php

namespace App\Domain\Support;

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
            $this->outbox->publish($actor, $model, $action, $payload ?: self::publicAttributes($model));

            return $model;
        });
    }

    private static function publicAttributes(Model $model): array
    {
        return collect($model->attributesToArray())->except($model->getHidden())->all();
    }
}
