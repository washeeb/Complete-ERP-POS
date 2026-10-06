<?php

namespace App\Domain\Channels;

use App\Models\OutboxEvent;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Each consumer of the outbox reads events after its own cursor. The cursor row is claimed with
 * FOR UPDATE SKIP LOCKED, so two relay processes never handle the same consumer at once, and a
 * crash simply re-delivers from the last saved position (handlers are idempotent).
 */
final class OutboxConsumers
{
    /** @param Closure(OutboxEvent): void $handle @return int events handled */
    public function consume(string $consumer, Closure $handle, int $batch = 200, ?Closure $filter = null): int
    {
        DB::table('outbox_cursors')->insertOrIgnore(['consumer' => $consumer, 'last_seq' => $this->startingPoint($consumer), 'updated_at' => now()]);

        return DB::transaction(function () use ($consumer, $handle, $batch, $filter) {
            $cursor = DB::selectOne('SELECT last_seq FROM outbox_cursors WHERE consumer = ? FOR UPDATE SKIP LOCKED', [$consumer]);
            if (! $cursor) {
                return 0; // another relay is on it
            }
            $events = OutboxEvent::withoutGlobalScopes()->where('seq', '>', $cursor->last_seq)->orderBy('seq')->limit($batch)->get();
            $n = 0;
            foreach ($events as $e) {
                if (! $filter || $filter($e)) {
                    $handle($e);
                    $n++;
                }
            }
            if ($events->isNotEmpty()) {
                DB::table('outbox_cursors')->where('consumer', $consumer)->update(['last_seq' => $events->last()->seq, 'updated_at' => now()]);
            }

            return $n;
        });
    }

    /** A new consumer starts from now: live clients reload on connect, and a new store gets a full push when activated. */
    private function startingPoint(string $consumer): int
    {
        return (int) (OutboxEvent::withoutGlobalScopes()->max('seq') ?? 0);
    }
}
