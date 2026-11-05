<?php

namespace App\Http\Controllers;

use App\Domain\Licensing\Vendor\LicenseSync;
use App\Domain\Licensing\Vendor\SyncDevices;
use App\Domain\Support\DomainException;
use App\Http\Middleware\VerifySyncSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The SES Licensing desktop app's connection to the licence server (only when LICENSE_SERVER=true). */
class LicenseSyncController extends Controller
{
    public function register(Request $request, SyncDevices $devices): JsonResponse
    {
        abort_unless(config('license.server.enabled'), 404);
        VerifySyncSignature::requireTls($request);
        $d = $request->validate(['code' => ['required', 'string', 'max:64'], 'name' => ['required', 'string', 'max:80'], 'public_key' => ['required', 'string', 'max:64']]);
        try {
            $device = $devices->register($d['code'], $d['name'], $d['public_key'], $request->ip());
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['device_id' => $device->id, 'name' => $device->name, 'server_time' => now()->toIso8601String()], 201);
    }

    public function pull(Request $request, LicenseSync $sync): JsonResponse
    {
        $d = $request->validate(['cursor' => ['required', 'integer', 'min:0'], 'limit' => ['integer', 'min:1', 'max:500']]);

        return response()->json($sync->pull($request->attributes->get('sync_device'), (int) $d['cursor'], (int) ($d['limit'] ?? 200)));
    }

    public function push(Request $request, LicenseSync $sync): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key');
        abort_unless(strlen($key) >= 16 && strlen($key) <= 64, 400, 'Idempotency-Key is required.');
        $request->validate(['changes' => ['present', 'array', 'max:200'], 'changes.*.entity' => ['required', 'string', 'max:12'], 'changes.*.id' => ['required', 'string', 'max:36'],
            'changes.*.base_version' => ['required', 'integer', 'min:0'], 'changes.*.data' => ['required', 'array']]);
        $r = $sync->push($request->attributes->get('sync_device'), $request->input('changes', []), $key);

        return response()->json(['results' => $r['results']], 200, $r['replayed'] ? ['Idempotency-Replayed' => 'true'] : []);
    }

    /** A short-lived link to the update description, if a new version of the app was put on this server. */
    public function update(): JsonResponse
    {
        return response()->json(['manifest' => self::release() ? URL::temporarySignedRoute('license-sync.update.manifest', now()->addMinutes(15)) : null]);
    }

    /** In the form the app's updater reads. The installer is checked against the signature in here before it runs. */
    public function updateManifest(): JsonResponse
    {
        $r = self::release();
        abort_unless($r && config('license.server.enabled'), 404);

        return response()->json(['version' => $r['version'], 'notes' => $r['notes'] ?? '', 'pub_date' => $r['pub_date'] ?? null,
            'platforms' => ['windows-x86_64' => ['signature' => $r['signature'], 'url' => URL::temporarySignedRoute('license-sync.update.download', now()->addMinutes(30))]]]);
    }

    public function updateDownload(): BinaryFileResponse
    {
        $r = self::release();
        abort_unless($r && config('license.server.enabled'), 404);

        return response()->download(storage_path('app/ses-licensing/'.basename($r['file'])));
    }

    /** What `npm run release` wrote, once it and its installer are in storage/app/ses-licensing. */
    private static function release(): ?array
    {
        $dir = storage_path('app/ses-licensing/');
        $r = is_file($dir.'latest.json') ? json_decode((string) file_get_contents($dir.'latest.json'), true) : null;

        return is_array($r) && isset($r['version'], $r['signature'], $r['file']) && is_file($dir.basename($r['file'])) ? $r : null;
    }
}
