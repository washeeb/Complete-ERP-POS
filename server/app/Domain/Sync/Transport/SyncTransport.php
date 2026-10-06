<?php

namespace App\Domain\Sync\Transport;

use App\Models\SyncPeer;

/** How this node talks to a peer. HTTP in real life; in-process in tests. */
interface SyncTransport
{
    public function hello(SyncPeer $peer): array;

    /** @return array{acked_seq: int, results: list<array>} */
    public function push(SyncPeer $peer, array $events): array;

    /** @return array{events: list<array>, last_seq: int, has_more: bool} */
    public function pull(SyncPeer $peer, int $after, int $limit): array;
}
