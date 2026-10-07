<?php

namespace App\Http\Controllers;

use App\Domain\Licensing\Vendor\CheckinServer;
use App\Domain\Licensing\Vendor\VendorKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Synclytics licence server: answers installations' check-ins (only when LICENSE_SERVER=true). */
class LicenseServerController extends Controller
{
    public function checkin(Request $request): JsonResponse
    {
        abort_unless(config('license.server.enabled'), 404);
        $report = $request->validate([
            'license_id' => ['required', 'uuid'], 'installation_id' => ['required', 'string', 'max:60'], 'fingerprint' => ['required', 'string', 'max:64'],
            'build_id' => ['nullable', 'string', 'max:60'], 'tampered' => ['boolean'], 'problems' => ['array', 'max:20'], 'counts' => ['array'],
            'version' => ['nullable', 'string', 'max:40'], 'nonce' => ['required', 'string', 'max:64'], 'sent_at' => ['nullable', 'string', 'max:40'],
        ]);

        return response()->json(CheckinServer::answer($report, $request->ip(), VendorKeys::load()));
    }
}
