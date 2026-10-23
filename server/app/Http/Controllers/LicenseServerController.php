<?php

namespace App\Http\Controllers;

use App\Domain\Licensing\Vendor\CheckinServer;
use App\Domain\Licensing\Vendor\VendorKeys;
use App\Models\Vendor\VendorLicense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/** The licence server (vendor side): answers installations' check-ins (only when LICENSE_SERVER=true). */
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

    /** An installation's hourly health report, kept on its licence for the support console (the latest one only). */
    public function health(Request $request): JsonResponse
    {
        abort_unless(config('license.server.enabled'), 404);
        $r = $request->validate([
            'license_id' => ['required', 'uuid'], 'installation_id' => ['required', 'string', 'max:60'], 'version' => ['nullable', 'string', 'max:40'],
            'checks' => ['present', 'array', 'max:40'], 'checks.*.check' => ['required', 'string', 'max:80'], 'checks.*.status' => ['required', 'in:ok,warning,fail'],
            'checks.*.detail' => ['nullable', 'string', 'max:300'],
            'devices' => ['array'], 'devices.active' => ['integer'], 'devices.with_problems' => ['integer'], 'devices.problems' => ['array', 'max:10'], 'devices.problems.*' => ['string', 'max:300'],
            'support' => ['array'], 'support.open_reports' => ['array', 'max:10'], 'support.open_reports.*.number' => ['string', 'max:20'],
            'support.open_reports.*.summary' => ['nullable', 'string', 'max:200'], 'support.open_reports.*.opened_at' => ['nullable', 'string', 'max:40'],
            'support.access_open' => ['array', 'max:2'], 'support.access_open.*' => ['in:view,control'],
            'sent_at' => ['nullable', 'string', 'max:40'],
        ]);
        // The same rule as check-ins: a report counts only from the installation the licence belongs to.
        $updated = VendorLicense::whereKey($r['license_id'])->where('installation_id', $r['installation_id'])->whereNull('revoked_at')
            ->update(['health' => json_encode(Arr::except($r, ['license_id', 'installation_id'])), 'health_at' => now()]);

        return response()->json(['ok' => $updated === 1], $updated === 1 ? 200 : 404);
    }
}
