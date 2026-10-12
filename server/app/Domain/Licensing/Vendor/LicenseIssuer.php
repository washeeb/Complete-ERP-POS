<?php

namespace App\Domain\Licensing\Vendor;

use App\Domain\Licensing\LicenseKey;
use App\Domain\Licensing\LicenseService;
use App\Domain\Licensing\Signer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Issues signed licence keys (vendor side). */
final class LicenseIssuer
{
    /**
     * @param  array{customer: string, request_code: string, expires_at: string, branches?: ?int, devices?: ?int, users?: ?int, features?: ?array, modules?: ?array,
     *               grace_days?: int, checkin_days?: ?int, server_url?: ?string, dev?: bool}  $o
     * @return array{license_id: string, key: string, payload: array}
     */
    public static function issue(array $o, array $keys, ?string $issuedBy = null, bool $record = true): array
    {
        $req = LicenseService::readInstallationCode($o['request_code']);
        $payload = [
            'v' => 1, 'kid' => $keys['kid'], 'license_id' => (string) Str::uuid7(), 'customer' => $o['customer'],
            'installation_id' => $req['installation_id'], 'fingerprint' => $req['fingerprint'],
            'issued_at' => now()->toIso8601String(), 'expires_at' => Carbon::parse($o['expires_at'])->endOfDay()->toIso8601String(),
            'grace_days' => (int) ($o['grace_days'] ?? 14), 'checkin_days' => array_key_exists('checkin_days', $o) ? $o['checkin_days'] : 30, 'server_url' => $o['server_url'] ?? null,
            'limits' => ['branches' => $o['branches'] ?? null, 'devices' => $o['devices'] ?? null, 'users' => $o['users'] ?? null],
            'features' => $o['features'] ?? null, 'modules' => $o['modules'] ?? null,
        ] + (! empty($o['dev']) ? ['dev' => true] : []);
        $key = LicenseKey::encode($payload, Signer::sign($payload, $keys['secret']));
        if ($record) {
            DB::table('vendor_licenses')->insert(['id' => $payload['license_id'], 'customer' => $o['customer'], 'installation_id' => $req['installation_id'],
                'payload' => json_encode($payload), 'license_key' => $key, 'issued_by' => $issuedBy ?? config('brand.vendor'), 'created_at' => now(), 'updated_at' => now()]);
        }

        return ['license_id' => $payload['license_id'], 'key' => $key, 'payload' => $payload];
    }
}
