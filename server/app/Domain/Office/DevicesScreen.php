<?php

namespace App\Domain\Office;

use App\Domain\Org\ArchiveService;
use App\Domain\Remote\DeviceHealth;
use App\Filament\Resources\Computers\ComputerResource;
use App\Filament\Resources\Devices\DeviceResource;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\Computer;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Shift;
use App\Models\Till;
use App\Models\User;
use App\Support\ClientInfo;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;

/**
 * Devices and computers in the new back office (settings and admin, part 6 of the rewrite), as the current screens:
 * the tills, handhelds, kiosks and kitchen displays (status, code expiry, last seen, health, last used from), Register
 * (the enrolment code shown once), New code, Revoke, Send a fix and its history, View screen and Take control on the
 * relay, Archive and Restore; and the computers that used the back office (Name it, What it did), through DeviceService,
 * DeviceHealth and ArchiveService (DevicesController).
 */
final class DevicesScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public function __construct(private readonly User $user) {}

    public static function canDevices(): bool
    {
        return DeviceResource::canViewAny();
    }

    public static function canComputers(): bool
    {
        return ComputerResource::canAccess();
    }

    /** @return array<string, string> the branches this person may register devices at */
    public static function branches(): array
    {
        $ids = Erp::branchIds('admin.devices.manage');

        return Branch::when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('code')->get()->mapWithKeys(fn ($b) => [$b->id => "{$b->code} · {$b->name}"])->all();
    }

    public static function query(): Builder
    {
        return DeviceResource::getEloquentQuery()->with('branch', 'till');
    }

    /** @param  array<string, mixed>  $p  q, show (in use | archived | both), page, per */
    public function devices(array $p): array
    {
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $q = self::query();
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where('devices.name', 'ilike', "%{$search}%");
        }
        match ($p['show'] ?? '') {
            'archived' => $q->whereNotNull('devices.archived_at'),
            'both' => null,
            default => $q->whereNull('devices.archived_at'),
        };
        $remote = Erp::user()?->isSupport() || Erp::canAnywhere('remote.control');

        return ['total' => (clone $q)->count(), 'page' => $page, 'per' => $per,
            'remote_link' => config('remote.relay_url') !== '' && $remote ? (string) app(Settings::class)->get('remote.agent_link', $this->user->tenant_id) : null,
            'remote_available' => config('remote.relay_url') !== '' && $remote,
            'rows' => $q->orderByDesc('devices.created_at')->forPage($page, $per)->get()->map(fn (Device $d) => $this->rowOf($d))->all()];
    }

    public function rowOf(Device $d): array
    {
        $health = app(DeviceHealth::class);
        $archive = app(ArchiveService::class);
        $allowed = $archive->allowed($this->user, $d);
        $level = DeviceResource::remoteLevel($d);

        return ['id' => $d->id, 'name' => $d->name, 'kind' => Device::KINDS[$d->kind] ?? $d->kind, 'branch' => $d->branch?->code, 'till' => $d->till?->code, 'status' => $d->status,
            'code_expires' => $d->status === 'pending' ? $d->enrollment_expires_at?->toIso8601String() : null, 'last_seen' => $d->last_seen_at?->toIso8601String(),
            'health' => $d->health_at === null || ! $d->isActive() ? null : (implode('; ', $health->problems($d)) ?: 'OK'),
            'health_details' => $d->health ? collect($d->health)->map(fn ($v, $k) => "{$k}: ".(is_bool($v) ? ($v ? 'yes' : 'no') : ($v ?? '—')))->implode(' · ').' · reported '.$d->health_at?->diffForHumans() : null,
            'used_from' => ClientInfo::summary($d->last_client, $d->last_ip), 'mac' => $d->last_client['mac'] ?? null, 'archived' => $d->archived_at !== null,
            'can_view' => $level !== null, 'can_control' => $level === 'control',
            'view_url' => $level !== null ? route('remote.connect', [$d, 'view']) : null, 'control_url' => $level === 'control' ? route('remote.connect', [$d, 'control']) : null,
            'can_fix' => $d->isActive() && in_array($d->kind, ['pos', 'handheld'], true) && $health->canFix($this->user, $d),
            'has_fixes' => DeviceCommand::where('device_id', $d->id)->exists(),
            // A shift still open on this device: revoking does not close it; the replacement till continues it, or a manager closes it.
            'open_shift' => ($sh = Shift::where('device_id', $d->id)->where('status', 'open')->first())
                ? ['id' => $sh->id, 'opened_by' => $sh->opened_by_name, 'opened_at' => $sh->opened_at->toIso8601String()] : null,
            'can_reissue' => $d->status === 'pending', 'can_revoke' => $d->status !== 'revoked',
            'can_archive' => ! $d->archived_at && $allowed, 'archive_blockers' => ! $d->archived_at && $allowed ? $archive->blockers($d) : [], 'can_restore' => (bool) $d->archived_at && $allowed];
    }

    public function options(?string $branchId): array
    {
        $branches = self::branches();
        $branchId = isset($branches[(string) $branchId]) ? $branchId : null;
        $pairs = fn (array $a) => array_map(fn ($k, $v) => ['id' => (string) $k, 'label' => (string) $v], array_keys($a), $a);

        return ['branches' => $pairs($branches), 'commands' => $pairs(DeviceCommand::COMMANDS),
            'tills' => $branchId ? $pairs(Till::where('branch_id', $branchId)->orderBy('code')->pluck('name', 'id')->all()) : [],
            'kinds' => $pairs(Device::kindsFor($branchId))];
    }

    public function fixes(Device $d): array
    {
        return DeviceCommand::where('device_id', $d->id)->latest()->limit(20)->get()->map(fn ($c) => ['when' => $c->created_at?->toIso8601String(),
            'what' => DeviceCommand::COMMANDS[$c->command] ?? $c->command, 'by' => $c->requested_by_name, 'status' => ucfirst((string) $c->status), 'result' => $c->result])->all();
    }

    /** @param  array<string, mixed>  $p  q, page, per */
    public function computers(array $p): array
    {
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $q = Computer::query();
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where('name', 'ilike', "%{$search}%");
        }

        return ['total' => (clone $q)->count(), 'page' => $page, 'per' => $per,
            'rows' => $q->orderByDesc('last_seen_at')->forPage($page, $per)->get()->map(fn (Computer $c) => [
                'id' => $c->id, 'label' => $c->label(), 'name' => $c->name, 'machine' => trim(($c->last_client['computer'] ?? '').' '.($c->last_client['mac'] ?? '')) ?: null,
                'last_user' => $c->last_user_name, 'last_seen' => $c->last_seen_at?->toIso8601String(), 'ip' => $c->last_ip, 'lookup' => ClientInfo::lookupUrl($c->last_ip),
                'browser' => $c->last_client['browser'] ?? null, 'first_seen' => $c->first_seen_at?->toDateString(),
                'activity' => '/office/audit-log?'.http_build_query(['computer' => $c->computer_key]),
            ])->all()];
    }
}
