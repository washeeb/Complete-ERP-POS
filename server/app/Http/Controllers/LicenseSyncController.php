<?php

namespace App\Http\Controllers;

use App\Domain\Licensing\Vendor\LicenseSync;
use App\Domain\Licensing\Vendor\SyncDevices;
use App\Domain\Support\DomainException;
use App\Http\Middleware\VerifySyncSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
