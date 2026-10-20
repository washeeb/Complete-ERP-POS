<?php

namespace App\Domain\Launch;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Mail\PlainMessage;
use App\Models\GoLivePlan;
use App\Models\SupportReport;
use App\Models\Tenant;
use App\Security\Authorizer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Report a problem" (Phase 14 pilot support): anyone signed in — back office or till — can describe what went wrong.
 * The report keeps what the screen and the server knew at that moment (page, versions, the server's recent error lines)
 * and is e-mailed to the support address of the go-live plan (or ERP_SUPPORT_EMAIL). Reporting needs no permission:
 * it changes no business data. Reading and closing reports needs support.view.
 */
final class SupportReports
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly DocumentNumbers $numbers) {}

    /**
     * @param  array<string, mixed>  $context  from the screen: browser, screen size, till app version, the till's last errors
     * @param  ?string  $screenshot  a picture of the screen: raw image bytes or a data URL (PNG / JPEG / WebP, up to 5 MB)
     */
    public function report(Actor $actor, string $source, string $message, ?string $page = null, array $context = [], ?string $deviceId = null, ?string $branchId = null,
        ?string $screenshot = null): SupportReport
    {
        if (! $actor->user && ! $deviceId) {
            throw new AccessDenied;
        }
        $message = trim($message);
        if (mb_strlen($message) < 5) {
            throw new DomainException('Describe the problem in a few words: what you did, and what happened.');
        }
        if (! isset(SupportReport::SOURCES[$source])) {
            throw new DomainException('Unknown source.');
        }
        $context = array_slice(array_map(fn ($v) => is_scalar($v) || $v === null ? mb_substr((string) $v, 0, 500) : array_slice((array) $v, 0, 20), $context), 0, 20, true);
        $context['server'] = ['version' => config('erp.version'), 'node' => config('erp.node.id'), 'role' => config('erp.node.role'), 'php' => PHP_VERSION,
            'practice_company' => Tenant::isPractice($actor->tenantId), 'recent_errors' => self::recentErrors()];

        $report = $this->recorder->transaction(function () use ($actor, $source, $message, $page, $context, $deviceId, $branchId) {
            $r = new SupportReport;
            $r->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'support_report'),
                'user_id' => $actor->id, 'user_name' => mb_substr($actor->name, 0, 120), 'source' => $source, 'device_id' => $deviceId, 'branch_id' => $branchId,
                'page' => $page ? mb_substr($page, 0, 255) : null, 'message' => mb_substr($message, 0, 5000), 'context' => $context, 'status' => 'open']);

            return $this->recorder->save($actor, $r, 'support.reported', branchId: $branchId);
        });
        if ($screenshot !== null && ($path = $this->storeScreenshot($report, $screenshot))) {
            $report->forceFill(['screenshot_path' => $path])->save();
        }
        $this->send($report);

        return $report->fresh();
    }

    /** Keep the picture privately; an unreadable one is dropped (the report still counts). */
    private function storeScreenshot(SupportReport $report, string $data): ?string
    {
        if (str_starts_with($data, 'data:')) {
            if (! preg_match('#^data:image/(png|jpeg|webp);base64,(.+)$#s', $data, $m)) {
                return null;
            }
            $data = (string) base64_decode($m[2], true);
        }
        if ($data === '' || strlen($data) > 5_000_000 || ! ($info = @getimagesizefromstring($data))) {
            return null;
        }
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$info['mime']] ?? null;
        if (! $ext) {
            return null;
        }
        $path = "support-screenshots/{$report->id}.{$ext}";
        Storage::disk('local')->put($path, $data);

        return $path;
    }

    /** E-mail the report to support. A failure is kept on the report (Support → Problem reports shows it) and retried by "Send again". */
    public function send(SupportReport $report): bool
    {
        $to = self::address($report->tenant_id);
        if (! $to) {
            $report->forceFill(['send_error' => 'No support e-mail address (System → Go-live → Pilot support, or ERP_SUPPORT_EMAIL).'])->save();

            return false;
        }
        $tenant = Tenant::find($report->tenant_id);
        $c = $report->context ?? [];
        $text = "Problem report {$report->number} from {$tenant->name}".($c['server']['practice_company'] ?? false ? ' (PRACTICE company)' : '')."\n\n"
            ."From: {$report->user_name} · ".SupportReport::SOURCES[$report->source].($report->page ? " · {$report->page}" : '')."\n"
            .'When: '.$report->created_at->timezone($tenant->timezone ?: 'UTC')->format('j M Y H:i')."\n\n"
            ."What happened:\n{$report->message}\n\n"
            ."Details for support:\n".json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        try {
            Mail::to($to)->send(new PlainMessage("[{$tenant->name}] Problem {$report->number}: ".Str::limit(str_replace("\n", ' ', $report->message), 60), $text,
                $report->screenshot_path, $report->screenshot_path ? "{$report->number}-screen.".pathinfo($report->screenshot_path, PATHINFO_EXTENSION) : null));
            $report->forceFill(['sent_at' => now(), 'send_error' => null])->save();

            return true;
        } catch (Throwable $e) {
            Log::warning('Problem report not e-mailed', ['report' => $report->number, 'error' => $e->getMessage()]);
            $report->forceFill(['send_error' => Str::limit($e->getMessage(), 250)])->save();

            return false;
        }
    }

    public function close(Actor $actor, SupportReport $report, string $resolution): SupportReport
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'support.view')) {
            throw new AccessDenied;
        }
        if (trim($resolution) === '') {
            throw new DomainException('Say what was done about it.');
        }
        $report->forceFill(['status' => 'closed', 'resolution' => mb_substr(trim($resolution), 0, 255), 'closed_by_name' => mb_substr($actor->name, 0, 120), 'closed_at' => now()]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $report, 'support.closed', $resolution));
    }

    public static function address(string $tenantId): ?string
    {
        $plan = GoLivePlan::withoutGlobalScopes()->where('tenant_id', $tenantId)->value('support_email');
        if (! $plan && ($real = Tenant::whereKey($tenantId)->value('practice_of'))) { // a practice company reports to the real company's support
            $plan = GoLivePlan::withoutGlobalScopes()->where('tenant_id', $real)->value('support_email');
        }

        return $plan ?: (config('erp.support_email') ?: null);
    }

    /** The server's last error lines (today's log), shortened: what support needs to start looking. */
    public static function recentErrors(int $max = 8): array
    {
        $file = storage_path('logs/laravel.log');
        if (! is_file($file)) {
            $daily = glob(storage_path('logs/laravel-*.log')) ?: [];
            rsort($daily);
            $file = $daily[0] ?? null;
        }
        if (! $file || ! is_readable($file)) {
            return [];
        }
        $size = filesize($file);
        $h = fopen($file, 'r');
        fseek($h, max(0, $size - 200_000));
        $tail = (string) stream_get_contents($h);
        fclose($h);
        preg_match_all('/^\[(\d{4}-\d{2}-\d{2}[ T][\d:.]+)[^\]]*\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY|WARNING): (.*)$/m', $tail, $m, PREG_SET_ORDER);
        $since = now()->subDay()->format('Y-m-d H:i:s');

        return array_values(array_slice(array_map(fn ($x) => "{$x[1]} {$x[2]}: ".Str::limit($x[3], 300),
            array_filter($m, fn ($x) => str_replace('T', ' ', substr($x[1], 0, 19)) >= $since)), -$max));
    }
}
