<?php

namespace App\Domain\Office;

use App\Domain\Backup\Destinations;
use App\Domain\Integrations\IntegrationsOverview;
use App\Domain\Launch\SupportReports;
use App\Domain\Licensing\LicenseManager;
use App\Domain\Licensing\LicenseService;
use App\Domain\Support\SystemHealth;
use App\Filament\Pages\Backups;
use App\Filament\Pages\IntegrationsPage;
use App\Filament\Pages\LicensePage;
use App\Filament\Pages\SystemHealthPage;
use App\Filament\Resources\AuditEvents\AuditEventResource;
use App\Filament\Resources\SupportReports\SupportReportResource;
use App\Filament\Support\Erp;
use App\Models\AuditEvent;
use App\Models\BackupDestination;
use App\Models\BackupRun;
use App\Models\Computer;
use App\Models\License;
use App\Models\SupportReport;
use App\Models\User;
use App\Support\ClientInfo;
use App\Support\Plain;
use App\Support\UserPrefs;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * System in the new back office, first half (part 22 of the rewrite), as the current pages: Health, Backups, Licence,
 * Audit log, Problem reports and Integrations. Same checks, queries and rules: SystemHealth, BackupService and
 * Destinations, LicenseService, AuditVerifier and SupportReports (SystemController).
 */
final class SystemScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public function __construct(private readonly User $user) {}

    private static function choices(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private static function page(array $p): array
    {
        return [in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25, max(1, (int) ($p['page'] ?? 1))];
    }

    private function tz(): string
    {
        return $this->user->tenant?->timezone ?: 'UTC';
    }

    private function when(?Carbon $at): ?string
    {
        return $at?->toIso8601String();
    }

    // -------------------------------------------------------------------------------------------- Health

    public static function canHealth(): bool
    {
        return SystemHealthPage::canAccess();
    }

    public function health(): array
    {
        return ['checks' => array_map(fn ($c) => ['check' => $c['check'], 'status' => $c['status'], 'detail' => $c['detail']], app(SystemHealth::class)->run()),
            'slow_over' => number_format(config('erp.slow_request_ms') / 1000, 1),
            'slow' => DB::table('slow_requests')->where('created_at', '>=', now()->subDays(7))
                ->groupBy('method', 'path')->selectRaw('method, path, COUNT(*) AS times, ROUND(AVG(ms)) AS avg_ms, MAX(ms) AS max_ms, ROUND(AVG(queries)) AS avg_queries, MAX(created_at) AS last_at')
                ->orderByRaw('COUNT(*) * AVG(ms) DESC')->limit(20)->get()->map(fn ($p) => ['id' => $p->method.' '.$p->path, 'page' => $p->method.' '.$p->path, 'times' => (int) $p->times,
                    'avg' => number_format($p->avg_ms / 1000, 1).' s', 'max' => number_format($p->max_ms / 1000, 1).' s', 'queries' => (int) $p->avg_queries,
                    'last' => Carbon::parse($p->last_at)->diffForHumans()])->all()];
    }

    // -------------------------------------------------------------------------------------------- Backups

    public static function canBackups(): bool
    {
        return Backups::canAccess();
    }

    /** A destination's settings for its form: secrets never leave the server (their boxes start empty; empty keeps them). */
    private static function destinationConfig(BackupDestination $d): array
    {
        return collect(Destinations::FIELDS[$d->type] ?? [])->mapWithKeys(fn ($f, $k) => [$k => $f[1] ? '' : (string) ($d->config[$k] ?? '')])->all();
    }

    public function backups(): array
    {
        return ['passphrase_set' => mb_strlen((string) config('backup.passphrase')) >= 12,
            'types' => collect(Destinations::TYPES)->map(fn ($label, $type) => ['id' => $type, 'label' => Destinations::allowed($type) || ! in_array($type, Destinations::PAID, true) ? $label : $label.' (paid add-on)'])->values()->all(),
            'fields' => collect(Destinations::FIELDS)->map(fn ($list) => collect($list)->map(fn ($f, $k) => ['key' => $k, 'label' => $f[0], 'secret' => (bool) $f[1]])->values()->all())->all(),
            'destinations' => BackupDestination::orderBy('name')->get()->map(fn (BackupDestination $d) => ['id' => $d->id, 'name' => $d->name, 'type' => $d->type,
                'type_label' => Destinations::TYPES[$d->type] ?? $d->type, 'keep' => (int) $d->keep, 'active' => (bool) $d->is_active, 'error' => $d->last_error,
                'last_ok' => $d->last_ok_at?->diffForHumans(), 'config' => self::destinationConfig($d)])->all(),
            'runs' => BackupRun::latest('started_at')->limit(20)->get()->map(fn (BackupRun $r) => ['id' => $r->id, 'when' => $r->started_at?->diffForHumans(), 'file' => $r->file_name,
                'status' => $r->status, 'error' => $r->error, 'size' => number_format($r->size_bytes / 1048576, 1).' MB',
                'copies' => collect($r->destinations ?? [])->map(fn ($x) => ['name' => $x['name'], 'ok' => (bool) $x['ok']])->all(), 'check' => $r->verify_result,
                'download' => in_array($r->status, ['ok', 'partial'], true) ? "/office-api/backups/{$r->id}/download" : null])->all()];
    }

    // -------------------------------------------------------------------------------------------- Licence

    public static function canLicence(): bool
    {
        return LicensePage::canAccess();
    }

    public static function canManageLicence(): bool
    {
        return Erp::can('setup.company.manage') && config('erp.node.role') !== 'branch';
    }

    public function licence(): array
    {
        $s = app(LicenseManager::class)->status();
        $page = new LicensePage;
        $u = $page->usage();
        $manage = self::canManageLicence();
        $code = $manage ? LicenseService::installationCode() : null;

        return ['state' => $s['state'], 'state_label' => ['unlicensed' => 'Not activated', 'active' => 'Active', 'warning' => 'Active — attention needed', 'grace' => 'Expired — grace period',
            'read_only' => 'Read-only'][$s['state']] ?? $s['state'], 'tone' => match ($s['state']) {
                'active' => 'success', 'warning', 'grace' => 'warning', default => 'danger'
            },
            'customer' => $s['customer'], 'short' => $s['short'], 'message' => $s['message'], 'read_only' => (bool) $s['read_only'],
            'expires_on' => $s['expires_at'] ? Carbon::parse($s['expires_at'])->format('j M Y') : null, 'days_left' => $s['days_left'],
            'usage' => $s['state'] === 'unlicensed' ? [] : collect(['branches' => 'Branches, including head office', 'devices' => 'Tills and devices', 'users' => 'Active users'])
                ->map(fn ($label, $k) => ['label' => $label, 'used' => $u[$k], 'max' => $s['limits'][$k] ?? null, 'full' => ($s['limits'][$k] ?? null) !== null && $u[$k] >= $s['limits'][$k]])->values()->all(),
            'covers' => $s['state'] === 'unlicensed' ? [] : collect($page->covers())->map(fn ($v, $k) => ['label' => $k, 'value' => $v])->values()->all(),
            'last_checkin' => License::where('is_current', true)->first()?->last_checkin_at?->format('j M Y H:i'), 'can_manage' => $manage,
            'can_checkin' => $manage && (bool) $s['checkin_url'], 'code' => $code,
            'qr' => $code ? 'data:image/svg+xml;base64,'.base64_encode((new Writer(new ImageRenderer(new RendererStyle(220, 2), new SvgImageBackEnd)))->writeString($code)) : null,
            'vendor' => config('brand.vendor'), 'phone' => config('brand.support_phone'), 'email' => config('brand.support_email'), 'website' => config('brand.website'),
            'product' => config('brand.name')];
    }

    // -------------------------------------------------------------------------------------------- Audit log

    public static function canAudit(): bool
    {
        return AuditEventResource::canViewAny();
    }

    public function auditQuery(): Builder
    {
        return AuditEventResource::getEloquentQuery();
    }

    public const SOURCES = ['web' => 'Back office', 'pos' => 'POS', 'api' => 'API', 'cli' => 'Command line', 'system' => 'System', 'sync' => 'Sync'];

    /** @param  array<string, mixed>  $p  q (who, action, IP), source, computer, signins (auth / pos), page, per */
    public function audit(array $p): array
    {
        $q = $this->auditQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('actor_name', 'ilike', "%{$s}%")->orWhere('action', 'ilike', "%{$s}%")->orWhere('ip', 'ilike', "%{$s}%"));
        }
        if (array_key_exists($p['source'] ?? '', self::SOURCES)) {
            $q->where('source', $p['source']);
        }
        $computers = Computer::labels();
        if (array_key_exists((string) ($p['computer'] ?? ''), $computers)) {
            $q->whereRaw("client->>'computer_key' = ?", [$p['computer']]);
        }
        match ($p['signins'] ?? '') {
            'auth' => $q->where('action', 'like', 'auth.%'),
            'pos' => $q->whereIn('action', ['pos.login', 'pos.login_failed', 'pos.pin_locked']),
            default => null,
        };
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();

        return ['total' => $total, 'page' => $page, 'per' => $per, 'sources' => self::choices(self::SOURCES), 'computers' => self::choices($computers),
            'can_verify' => Erp::can('audit.verify'),
            'rows' => $q->orderByDesc('seq')->forPage($page, $per)->get()->map(fn (AuditEvent $e) => ['id' => $e->id, 'seq' => (int) $e->seq, 'when' => $this->when($e->occurred_at),
                'by' => $e->actor_name, 'action' => Plain::action($e->action), 'record' => Plain::record($e->subject_type), 'source' => Plain::source($e->source),
                'computer' => ClientInfo::summary($e->client), 'ip' => $e->ip, 'reason' => $e->reason])->all()];
    }

    /** One entry in full: who, from where, the record, and each change in words (before → after). */
    public function auditEvent(AuditEvent $e): array
    {
        $c = $e->client ?? [];

        return ['seq' => (int) $e->seq, 'when' => $this->when($e->occurred_at), 'action' => Plain::action($e->action), 'action_key' => $e->action, 'by' => $e->actor_name,
            'source' => Plain::source($e->source), 'node' => $e->origin_node, 'record' => Plain::record($e->subject_type), 'record_id' => $e->subject_id, 'ip' => $e->ip,
            'ip_lookup' => ClientInfo::lookupUrl($e->ip), 'computer' => ($k = $c['computer_key'] ?? null) ? (Computer::labels()[$k] ?? 'Not listed') : null,
            'computer_name' => $c['computer'] ?? null, 'mac' => $c['mac'] ?? null, 'local_ip' => $c['local_ip'] ?? null, 'browser' => $c['browser'] ?? null, 'app' => $c['app'] ?? null,
            'reason' => $e->reason, 'hash' => $e->hash,
            'changes' => collect($e->changes ?? [])->map(fn ($pair, $key) => ['field' => Plain::field((string) $key), 'value' => is_array($pair) && count($pair) === 2 && array_is_list($pair)
                ? Plain::value((string) $key, $pair[0]).' → '.Plain::value((string) $key, $pair[1]) : Plain::value((string) $key, $pair)])->values()->all()];
    }

    // -------------------------------------------------------------------------------------------- Problem reports

    public static function canReports(): bool
    {
        return SupportReportResource::canViewAny();
    }

    /** @param  array<string, mixed>  $p  q, status (open by default; "all"), source, page, per */
    public function reports(array $p): array
    {
        $q = SupportReport::query();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('number', 'ilike', "%{$s}%")->orWhere('user_name', 'ilike', "%{$s}%")->orWhere('message', 'ilike', "%{$s}%"));
        }
        $status = (string) ($p['status'] ?? 'open');
        if (array_key_exists($status, SupportReport::STATUSES)) {
            $q->where('status', $status);
        }
        if (array_key_exists($p['source'] ?? '', SupportReport::SOURCES)) {
            $q->where('source', $p['source']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $to = SupportReports::address($this->user->tenant_id);

        return ['total' => $total, 'page' => $page, 'per' => $per, 'status' => $status, 'statuses' => self::choices(SupportReport::STATUSES), 'sources' => self::choices(SupportReport::SOURCES),
            'sent_to' => $to ? "New reports are e-mailed to {$to}." : 'No support e-mail address yet: set it in System → Go-live → Plan (or ERP_SUPPORT_EMAIL).',
            'rows' => $q->orderByDesc('created_at')->forPage($page, $per)->get()->map(fn (SupportReport $r) => $this->report($r))->all()];
    }

    public function report(SupportReport $r): array
    {
        return ['id' => $r->id, 'number' => $r->number, 'when' => $this->when($r->created_at), 'ago' => $r->created_at?->diffForHumans(), 'from' => $r->user_name,
            'source' => SupportReport::SOURCES[$r->source] ?? $r->source, 'page' => $r->page, 'message' => $r->message, 'status' => $r->status,
            'status_label' => SupportReport::STATUSES[$r->status] ?? $r->status, 'resolution' => $r->resolution, 'send_error' => $r->send_error,
            'screenshot' => $r->screenshot_path ? "/support-screenshot/{$r->id}" : null,
            'context' => json_encode($r->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }

    // -------------------------------------------------------------------------------------------- Integrations

    public static function canIntegrations(): bool
    {
        return IntegrationsPage::canAccess();
    }

    public function integrations(): array
    {
        $fmt = UserPrefs::dateTimeFormat();
        $cards = app(IntegrationsOverview::class)->cards((string) $this->user->tenant_id);

        return ['counts' => collect($cards)->reject(fn ($c) => $c['always'])->countBy('state')->all(),
            'cards' => array_map(fn ($c) => ['id' => $c['key'] ?? $c['title'], 'title' => $c['title'], 'state' => $c['always'] ? 'ok' : $c['state'],
                'state_label' => $c['always'] ? 'Available' : IntegrationsOverview::STATES[$c['state']], 'always' => (bool) $c['always'], 'what' => $c['what'], 'detail' => $c['detail'],
                'last_ok' => $c['last_ok'] ? $c['last_ok']->timezone($this->tz())->format($fmt).' ('.$c['last_ok']->diffForHumans().')' : null, 'failed' => (int) $c['failed'],
                'url' => $c['url'] ? (parse_url($c['url'], PHP_URL_PATH).(($q = parse_url($c['url'], PHP_URL_QUERY)) ? "?{$q}" : '')) : null, 'link' => $c['link']], $cards)];
    }
}
