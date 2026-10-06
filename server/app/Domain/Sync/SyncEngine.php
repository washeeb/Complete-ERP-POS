<?php

namespace App\Domain\Sync;

use App\Domain\Support\DomainException;
use App\Domain\Sync\Transport\SyncTransport;
use App\Models\OutboxEvent;
use App\Models\SyncPeer;
use Illuminate\Support\Facades\DB;

/**
 * One sync run with one peer: push our new events, then pull theirs. Batched (500), resumable
 * (cursors only move after the peer acknowledges / after we stored what we received), idempotent
 * (event ids), and bounded in time so a slow link never blocks the scheduler.
 */
final class SyncEngine
{
    public const BATCH = 500;

    public function __construct(private readonly SyncTransport $transport, private readonly SyncServer $server) {}

    /** @return array{pushed: int, pulled: int, applied: int, staged: int, failed: int, error: ?string} */
    public function run(SyncPeer $peer, int $timeBudgetSeconds = 50): array
    {
        $stats = ['pushed' => 0, 'pulled' => 0, 'applied' => 0, 'staged' => 0, 'failed' => 0, 'error' => null];
        $deadline = microtime(true) + $timeBudgetSeconds;
        $peer->forceFill(['last_attempt_at' => now()])->save();

        try {
            // Push: our own events in groups we send, after what the peer acknowledged.
            do {
                $batch = OutboxEvent::withoutGlobalScopes()->where('tenant_id', $peer->tenant_id)
                    ->where('origin_node', (string) config('erp.node.id'))->where('seq', '>', $peer->last_pushed_seq)
                    ->whereIn('sync_group', $peer->groupsWeSend())->orderBy('seq')->limit(self::BATCH)->get();
                if ($batch->isEmpty()) {
                    break;
                }
                $r = $this->transport->push($peer, $batch->map(fn ($e) => SyncServer::wire($e))->all());
                $peer->forceFill(['last_pushed_seq' => max($peer->last_pushed_seq, (int) $r['acked_seq'])])->save();
                $stats['pushed'] += $batch->count();
            } while ($batch->count() === self::BATCH && microtime(true) < $deadline);

            // Pull: their events after our cursor; stored first, then applied or staged by group mode.
            do {
                $r = $this->transport->pull($peer, $peer->last_pulled_seq, self::BATCH);
                $received = $this->server->receive($peer, $r['events']);
                foreach ($received['results'] as $x) {
                    match ($x['status']) {
                        'applied' => $stats['applied']++,
                        'staged' => $stats['staged']++,
                        'failed' => $stats['failed']++,
                        default => null,
                    };
                }
                $stats['pulled'] += count($r['events']);
                $peer->forceFill(['last_pulled_seq' => max($peer->last_pulled_seq, (int) $r['last_seq'])])->save();
            } while ($r['has_more'] && microtime(true) < $deadline);

            $this->server->retryFailed($peer);
            $peer->forceFill(['last_success_at' => now(), 'last_error' => null])->save();
        } catch (DomainException $e) {
            $stats['error'] = $e->getMessage();
            $peer->forceFill(['last_error' => $e->getMessage()])->save();
        }

        return $stats;
    }

    /** Apply (or reject) staged events a person has previewed. */
    public function decide(SyncPeer $peer, array $eventIds, bool $accept, ?string $userId): int
    {
        $n = 0;
        $events = $peer->inbox()->whereIn('id', $eventIds)->whereIn('status', ['staged', 'skipped'])->orderBy('origin_seq')->get();
        foreach ($events as $in) {
            if ($accept) {
                $in->forceFill(['decided_by' => $userId])->save();
                $n += $this->server->applyStored($in) ? 1 : 0;
            } else {
                $in->forceFill(['status' => 'rejected', 'decided_by' => $userId])->save();
                $n++;
            }
        }

        return $n;
    }

    /** Start over from the peer's first event (repairs a damaged branch; everything is idempotent). */
    public function resetForFullResync(SyncPeer $peer): void
    {
        DB::transaction(function () use ($peer) {
            $peer->inbox()->delete();
            $peer->forceFill(['last_pulled_seq' => 0])->save();
        });
    }
}
