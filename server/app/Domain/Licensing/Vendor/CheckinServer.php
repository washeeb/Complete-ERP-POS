<?php

namespace App\Domain\Licensing\Vendor;

use App\Domain\Licensing\Signer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The licence server's answer to a check-in (vendor side). Every check-in is kept: the same licence on two
 * machines, a modified copy, or usage over the licence shows up here. A renewed key (issued for the same
 * installation, superseding this one) is handed back automatically.
 */
final class CheckinServer
{
    public static function answer(array $report, ?string $ip, array $keys): array
    {
        $lic = DB::table('vendor_licenses')->where('id', $report['license_id'] ?? '')->first();
        $status = 'active';
        $message = null;
        $renewed = null;
        if (! $lic) {
            [$status, $message] = ['unknown_license', 'This licence is not known to '.config('brand.vendor').'.'];
        } elseif ($lic->revoked_at) {
            [$status, $message] = ['revoked', $lic->revoked_reason];
        } elseif ($lic->installation_id !== ($report['installation_id'] ?? null)) {
            [$status, $message] = ['revoked', 'This licence is being used on another installation.'];
        } elseif ($lic->superseded_by) {
            $renewed = DB::table('vendor_licenses')->where('id', $lic->superseded_by)->whereNull('revoked_at')->value('license_key');
        }
        DB::table('vendor_checkins')->insert(['id' => (string) Str::uuid7(), 'license_id' => (string) ($report['license_id'] ?? Str::uuid7()),
            'installation_id' => mb_substr((string) ($report['installation_id'] ?? ''), 0, 60), 'fingerprint' => mb_substr((string) ($report['fingerprint'] ?? ''), 0, 64),
            'build_id' => isset($report['build_id']) ? mb_substr((string) $report['build_id'], 0, 60) : null, 'tampered' => (bool) ($report['tampered'] ?? false),
            'report' => json_encode($report), 'ip' => $ip, 'answer' => $status, 'created_at' => now()]);
        $answer = ['license_id' => (string) ($report['license_id'] ?? ''), 'status' => $status, 'message' => $message, 'license_key' => $renewed,
            'nonce' => (string) ($report['nonce'] ?? ''), 'server_time' => now()->toIso8601String()];

        return ['answer' => $answer, 'kid' => $keys['kid'], 'sig' => Signer::sign($answer, $keys['secret'])];
    }
}
