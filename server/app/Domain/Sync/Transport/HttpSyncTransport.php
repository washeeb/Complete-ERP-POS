<?php

namespace App\Domain\Sync\Transport;

use App\Domain\Support\DomainException;
use App\Domain\Sync\SyncSigner;
use App\Models\SyncPeer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Signed, gzip-compressed HTTPS calls to the peer's /api/v1/sync endpoints. */
final class HttpSyncTransport implements SyncTransport
{
    public function hello(SyncPeer $peer): array
    {
        return $this->call($peer, 'GET', 'api/v1/sync/hello', '');
    }

    public function push(SyncPeer $peer, array $events): array
    {
        return $this->call($peer, 'POST', 'api/v1/sync/push', gzencode(json_encode(['events' => $events]), 6), gzip: true);
    }

    public function pull(SyncPeer $peer, int $after, int $limit): array
    {
        return $this->call($peer, 'GET', "api/v1/sync/pull?after={$after}&limit={$limit}", '');
    }

    private function call(SyncPeer $peer, string $method, string $pathAndQuery, string $body, bool $gzip = false): array
    {
        if (! $peer->base_url) {
            throw new DomainException("No address is set for {$peer->name}.", 'no_address');
        }
        $time = time();
        $path = parse_url('/'.$pathAndQuery, PHP_URL_PATH);
        $headers = [
            'Accept' => 'application/json',
            'Accept-Encoding' => 'gzip',
            'X-Sync-Node' => (string) config('erp.node.id'),
            'X-Sync-Tenant' => $peer->tenant_id,
            'X-Sync-Time' => (string) $time,
            'X-Sync-Signature' => SyncSigner::sign($peer->secret, $time, $method, $path, $body),
        ];
        if ($gzip) {
            $headers['Content-Encoding'] = 'gzip';
        }
        try {
            $request = Http::withHeaders($headers)->timeout(60)->connectTimeout(10)->retry(2, 500, throw: false);
            $url = rtrim($peer->base_url, '/').'/'.$pathAndQuery;
            $response = $method === 'GET'
                ? $request->get($url)
                : $request->withBody($body, 'application/json')->post($url);
        } catch (ConnectionException $e) {
            throw new DomainException("Cannot reach {$peer->name} ({$peer->base_url}): {$e->getMessage()}", 'unreachable');
        }
        if (! $response->successful()) {
            $detail = $response->json('detail') ?? $response->body();
            throw new DomainException("{$peer->name} answered {$response->status()}: ".mb_substr((string) $detail, 0, 300), 'peer_error');
        }

        return $response->json();
    }
}
