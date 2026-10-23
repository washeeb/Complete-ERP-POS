<?php

namespace App\Domain\Licensing;

use App\Domain\Remote\DeviceHealth;
use App\Domain\Support\SystemHealth;
use App\Models\Device;
use App\Models\License;
use App\Models\SupportAccessGrant;
use App\Models\SupportReport;
use App\Models\Tenant;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The hourly health report to SyncLytics' support console (owner request 2026-10-23): the server's own checks
 * (System → Health), the tills with problems, and open problem reports — so support sees a failing backup or a silent
 * till before the client calls. No sales, customers, prices or other business figures are sent. The owner can switch
 * it off (System → Settings → Security → Share system health with SyncLytics support).
 */
final class HealthReport
{
    public function __construct(private readonly SystemHealth $health, private readonly DeviceHealth $devices, private readonly Settings $settings) {}

    public function build(): array
    {
        $checks = collect($this->health->run())->map(fn ($c) => ['check' => $c['check'], 'status' => $c['status'], 'detail' => mb_substr($c['detail'], 0, 200)])->values()->all();
        $active = Device::withoutGlobalScopes()->where('status', 'active')->whereIn('kind', ['pos', 'handheld'])->get();
        $problems = $active->map(fn (Device $d) => ['device' => $d->name, 'problems' => $this->devices->problems($d)])->filter(fn ($r) => $r['problems'])->values();

        return [
            'license_id' => License::where('is_current', true)->value('id'),
            'installation_id' => DB::table('system_meta')->where('key', 'installation_id')->value('value'),
            'version' => (string) (config('erp.version') ?: 'dev'),
            'checks' => $checks,
            'devices' => ['active' => $active->count(), 'with_problems' => $problems->count(),
                'problems' => $problems->take(10)->map(fn ($r) => $r['device'].': '.mb_substr(implode(', ', (array) $r['problems']), 0, 160))->all()],
            'support' => [
                'open_reports' => SupportReport::withoutGlobalScopes()->where('status', 'open')->latest()->limit(10)->get()
                    ->map(fn (SupportReport $r) => ['number' => $r->number, 'summary' => Str::limit((string) $r->message, 100), 'opened_at' => $r->created_at?->toIso8601String()])->all(),
                'access_open' => SupportAccessGrant::withoutGlobalScopes()->active()->pluck('level')->unique()->values()->all(),
            ],
            'sent_at' => now()->toIso8601String(),
        ];
    }

    /** @return array{ok: bool, message: string} */
    public function send(): array
    {
        $lic = License::where('is_current', true)->first();
        $url = $lic?->payload['server_url'] ?? null;
        if (! $lic || ! $url || config('erp.node.role') === 'branch') {
            return ['ok' => false, 'message' => 'Nothing to report (no licence server, or a branch server).'];
        }
        $tenantId = (string) Tenant::query()->value('id');
        if ($tenantId !== '' && ! $this->settings->get('support.share_health', $tenantId)) {
            return ['ok' => false, 'message' => 'Sharing system health with support is switched off.'];
        }
        try {
            $res = Http::timeout(20)->acceptJson()->post(rtrim($url, '/').'/api/license/health', $this->build());

            return $res->successful() ? ['ok' => true, 'message' => 'Health report sent.'] : ['ok' => false, 'message' => 'The licence server refused the report ('.$res->status().').'];
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'The licence server could not be reached.'];
        }
    }
}
