<?php

namespace App\Http\Middleware;

use App\Domain\Licensing\Signer;
use App\Models\Vendor\VendorSyncDevice;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every sync request is signed by the device (Ed25519) over its method, path, time, nonce and body hash. A request more
 * than five minutes off the clock, or with a nonce seen before, is refused, so a recorded request cannot be replayed.
 */
class VerifySyncSignature
{
    public const WINDOW_SECONDS = 300;

    public const MAX_BODY_BYTES = 8_000_000;

    public static function message(string $method, string $path, string $time, string $nonce, string $body): string
    {
        return "ZX-SYNC-1\n".strtoupper($method)."\n/".ltrim($path, '/')."\n{$time}\n{$nonce}\n".hash('sha256', $body);
    }

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('license.server.enabled'), 404);
        self::requireTls($request);
        $id = (string) $request->header('X-Sync-Device');
        $time = (string) $request->header('X-Sync-Time');
        $nonce = (string) $request->header('X-Sync-Nonce');
        $sig = Signer::unb64((string) $request->header('X-Sync-Signature'));
        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            abort(413);
        }
        $device = preg_match('/^[0-9a-f-]{36}$/', $id) ? VendorSyncDevice::find($id) : null;
        $key = $device?->public_key ? base64_decode($device->public_key, true) : false;
        if (! $device || $device->status !== 'active' || $key === false || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! ctype_digit($time) || strlen($nonce) < 16 || strlen($nonce) > 64
            || ! sodium_crypto_sign_verify_detached($sig, self::message($request->method(), $request->path(), $time, $nonce, $request->getContent()), $key)) {
            abort(401, 'Device not recognised.');
        }
        if (abs(time() - (int) $time) > self::WINDOW_SECONDS) {
            abort(401, 'This PC\'s clock is more than five minutes out.');
        }
        if (DB::table('vendor_sync_nonces')->insertOrIgnore(['device_id' => $device->id, 'nonce' => $nonce, 'created_at' => now()]) === 0) {
            abort(409, 'Request already seen.');
        }
        $device->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip()])->saveQuietly();
        $request->attributes->set('sync_device', $device);

        return $next($request);
    }

    /** TLS only; plain HTTP is allowed on a developer's own machine and in tests. */
    public static function requireTls(Request $request): void
    {
        abort_unless($request->secure() || app()->environment(['local', 'testing']), 426, 'HTTPS is required.');
    }
}
