<?php

namespace App\Domain\Licensing;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\AuditLog;
use App\Domain\Support\Outbox;
use App\Models\License;
use App\Security\Authorizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Activation (offline: the customer sends the installation code, Synclytics sends back a signed key) and the
 * check-in with the Synclytics licence server (head office, daily): it reports what the installation is using and
 * whether this copy was modified, and receives a signed answer — still active, revoked, or a renewed key.
 */
final class LicenseService
{
    public function __construct(private readonly Authorizer $authz, private readonly AuditLog $audit, private readonly Outbox $outbox) {}

    /** The code the customer sends to Synclytics to get a key for this installation. */
    public static function installationCode(): string
    {
        return 'SYNREQ-'.Signer::b64(Signer::canonical([
            'installation_id' => DB::table('system_meta')->where('key', 'installation_id')->value('value'),
            'fingerprint' => MachineFingerprint::get(),
            'build_id' => ReleaseManifest::check()['build_id'],
        ]));
    }

    /** @return array{installation_id: string, fingerprint: string, build_id: ?string} */
    public static function readInstallationCode(string $code): array
    {
        $json = str_starts_with(trim($code), 'SYNREQ-') ? Signer::unb64(substr(trim($code), 7)) : false;
        $data = $json === false ? null : json_decode($json, true);
        if (! is_array($data) || empty($data['installation_id'])) {
            throw new LicenseException('That is not an installation code (it starts with SYNREQ-).', 'license_invalid');
        }

        return $data + ['fingerprint' => 'unknown', 'build_id' => null];
    }

    public function activate(Actor $actor, string $key): License
    {
        if ($actor->source !== 'system' && (! $actor->user || ! $this->authz->allows($actor->user, 'setup.company.manage'))) {
            throw new AccessDenied;
        }
        if (config('erp.node.role') === 'branch') {
            throw new LicenseException('Branch servers receive the licence from head office.', 'license_invalid');
        }
        $p = LicenseKey::verify($key);
        if (($p['installation_id'] ?? null) !== DB::table('system_meta')->where('key', 'installation_id')->value('value')) {
            throw new LicenseException('This key was issued for a different installation. Send Synclytics the installation code shown on this page.', 'license_invalid');
        }

        return LicenseManager::bypass(fn () => DB::transaction(function () use ($actor, $key, $p) {
            $old = License::where('is_current', true)->get();
            foreach ($old as $o) {
                $o->forceFill(['is_current' => false, 'version' => $o->version + 1])->save();
                $this->outbox->publish($actor, $o, 'license.replaced');
            }
            $lic = License::find($p['license_id']) ?? new License(['id' => $p['license_id']]);
            $lic->forceFill(['license_key' => preg_replace('/\s+/', '', $key), 'payload' => $p, 'activated_at' => now(), 'activated_by_name' => $actor->name,
                'is_current' => true, 'revoked_at' => null, 'checkin_status' => null, 'checkin_message' => null, 'last_checkin_at' => now(),
                'version' => ($lic->version ?? 0) + 1]);
            $lic->save();
            DB::table('system_meta')->where('key', 'moved_detected_at')->delete();
            $this->audit->record($actor, 'license.activated', $lic, ['license_id' => [null, $p['license_id']], 'expires_at' => [null, $p['expires_at']]]);
            $this->outbox->publish($actor, $lic, 'license.activated');
            LicenseManager::forget();

            return $lic;
        }));
    }

    /** What head office reports to the licence server. */
    public function report(): array
    {
        $m = ReleaseManifest::check();

        return [
            'license_id' => License::where('is_current', true)->value('id'),
            'installation_id' => DB::table('system_meta')->where('key', 'installation_id')->value('value'),
            'fingerprint' => MachineFingerprint::get(),
            'build_id' => $m['build_id'], 'tampered' => ! $m['ok'], 'problems' => array_slice($m['problems'], 0, 20),
            'counts' => ['branches' => DB::table('branches')->count(), 'devices' => DB::table('devices')->where('status', 'active')->count(),
                'users' => DB::table('users')->where('is_active', true)->count()],
            'version' => (string) config('app.version', 'dev'), 'nonce' => Str::random(24), 'sent_at' => now()->toIso8601String(),
        ];
    }

    /** @return array{ok: bool, status: ?string, message: string} */
    public function checkIn(): array
    {
        $lic = License::where('is_current', true)->first();
        $url = $lic?->payload['server_url'] ?? null;
        if (! $lic || ! $url || config('erp.node.role') === 'branch') {
            return ['ok' => false, 'status' => null, 'message' => 'Nothing to check in.'];
        }
        $report = $this->report();
        try {
            $res = Http::timeout(20)->acceptJson()->post(rtrim($url, '/').'/api/license/checkin', $report)->json();
        } catch (Throwable $e) {
            return ['ok' => false, 'status' => null, 'message' => 'The licence server could not be reached.'];
        }
        $answer = $res['answer'] ?? null;
        if (! is_array($answer) || ($answer['nonce'] ?? null) !== $report['nonce'] || ($answer['license_id'] ?? null) !== $lic->id
            || ! Signer::verifyWithTrusted($answer, (string) ($res['sig'] ?? ''), $res['kid'] ?? null)) {
            return ['ok' => false, 'status' => null, 'message' => 'The licence server answer could not be verified.'];
        }
        $actor = Actor::system((string) DB::table('tenants')->value('id'), 'Licence check-in', 'system');
        LicenseManager::bypass(function () use ($lic, $answer, $actor) {
            DB::transaction(function () use ($lic, $answer, $actor) {
                $lic->forceFill(['last_checkin_at' => now(), 'checkin_status' => $answer['status'], 'checkin_message' => $answer['message'] ?? null,
                    'revoked_at' => $answer['status'] === 'revoked' ? ($lic->revoked_at ?? now()) : null, 'version' => $lic->version + 1])->save();
                $this->audit->record($actor, 'license.checked_in', $lic, ['status' => [null, $answer['status']]]);
                $this->outbox->publish($actor, $lic, 'license.checked_in');
            });
            if (! empty($answer['license_key']) && $answer['status'] === 'active') {
                $this->activate($actor, $answer['license_key']); // renewal
            }
        });
        LicenseManager::forget();

        return ['ok' => true, 'status' => $answer['status'], 'message' => $answer['message'] ?? 'Licence confirmed.'];
    }
}
