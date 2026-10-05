<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Support\DomainException;
use App\Domain\Sync\SyncServer;
use App\Domain\Sync\SyncSigner;
use App\Http\Controllers\Controller;
use App\Models\SyncPeer;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Endpoints another node calls. Authenticated by the shared peer secret (HMAC), not by a user. */
class SyncController extends Controller
{
    public function hello(Request $request, SyncServer $server): Response
    {
        return $this->reply($request, $server->hello($this->peer($request)));
    }

    public function push(Request $request, SyncServer $server): Response
    {
        $peer = $this->peer($request);
        $raw = $request->getContent();
        $json = $request->header('Content-Encoding') === 'gzip' ? @gzdecode($raw) : $raw;
        $events = json_decode((string) $json, true)['events'] ?? null;
        if (! is_array($events) || count($events) > 1000) {
            throw new DomainException('Malformed sync batch.', 'malformed');
        }

        return $this->reply($request, $server->receive($peer, $events));
    }

    public function pull(Request $request, SyncServer $server): Response
    {
        $peer = $this->peer($request);

        return $this->reply($request, $server->serve($peer, (int) $request->query('after', 0), (int) $request->query('limit', 500)));
    }

    private function peer(Request $request): SyncPeer
    {
        $peer = SyncPeer::withoutGlobalScopes()->where('tenant_id', (string) $request->header('X-Sync-Tenant'))
            ->where('node_id', (string) $request->header('X-Sync-Node'))->where('is_active', true)->first();
        $fail = new DomainException('Unknown or disabled sync peer.', 'bad_signature');
        if (! $peer) {
            throw $fail;
        }
        SyncSigner::verify($peer->secret, $request->header('X-Sync-Time'), $request->method(), '/'.$request->path(),
            $request->getContent(), $request->header('X-Sync-Signature'));
        app(TenantContext::class)->set($peer->tenant_id);
        $peer->forceFill(['last_attempt_at' => now(), 'last_success_at' => now()])->saveQuietly();

        return $peer;
    }

    /** gzip the answer when the caller accepts it (sync batches compress ~10×). */
    private function reply(Request $request, array $data): Response
    {
        $json = json_encode($data);
        if (str_contains((string) $request->header('Accept-Encoding'), 'gzip') && strlen($json) > 1024) {
            return response(gzencode($json, 6), 200, ['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip']);
        }

        return response($json, 200, ['Content-Type' => 'application/json']);
    }
}
