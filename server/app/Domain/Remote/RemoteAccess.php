<?php

namespace App\Domain\Remote;

use App\Domain\Launch\SupportReports;
use App\Domain\Licensing\LicenseManager;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Mail\PlainMessage;
use App\Models\Branch;
use App\Models\SupportAccessGrant;
use App\Models\SupportReport;
use App\Models\Tenant;
use App\Models\User;
use App\Security\Authorizer;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Remote access to tills and computers (owner decisions 2026-10-20).
 *
 * - The company's own staff: a paid add-on (licence feature remote_access) plus the remote.view / remote.control
 *   permission at the device's branch.
 * - SyncLytics support: free, but only while the owner's support access grant is in force (a time, or until a
 *   problem report is closed), view or control, every branch or one.
 * - Consent per branch: unattended outside trading hours (default), always unattended, or always ask; every connection
 *   shows a banner on the device and is in the audit log (phase 2: the relay records the sessions).
 */
final class RemoteAccess
{
    public const ADDON = 'remote_access';

    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly Settings $settings,
        private readonly LicenseManager $license,
    ) {}

    /** Does the licence include the Remote access add-on (for the company's own staff)? */
    public function licensed(): bool
    {
        return $this->license->hasAddon(self::ADDON);
    }

    /** 'unattended' or 'ask', for a connection to a device of the branch at that moment (the branch's own clock). */
    public function consentFor(Branch $branch, ?CarbonInterface $at = null): string
    {
        $policy = (string) $this->settings->forBranch('remote.consent', $branch, $branch->tenant_id);
        if ($policy !== 'outside_hours') {
            return $policy === 'unattended' ? 'unattended' : 'ask';
        }

        return $this->trading($branch, $at) ? 'ask' : 'unattended';
    }

    /** Is the branch within its trading hours at that moment (its own clock)? */
    public function trading(Branch $branch, ?CarbonInterface $at = null): bool
    {
        [$open, $close] = self::hours((string) $this->settings->forBranch('remote.trading_hours', $branch, $branch->tenant_id));
        $local = ($at ?? now())->copy()->setTimezone($branch->timezone ?: (Tenant::find($branch->tenant_id)?->timezone ?: 'Africa/Accra'));
        $minute = $local->hour * 60 + $local->minute;

        return $open <= $close ? ($minute >= $open && $minute < $close) : ($minute >= $open || $minute < $close); // across midnight
    }

    /** "07:00-21:00" → [420, 1260] minutes; an unreadable value means "always trading" (ask), the safe side. */
    public static function hours(string $value): array
    {
        if (! preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $value, $m) || $m[1] > 23 || $m[3] > 24 || $m[2] > 59 || $m[4] > 59) {
            return [0, 24 * 60];
        }

        return [(int) $m[1] * 60 + (int) $m[2], (int) $m[3] * 60 + (int) $m[4]];
    }

    /** What the company's own person may do on the branch's devices: 'control', 'view' or null. */
    public function staffLevel(User $user, Branch $branch): ?string
    {
        if (! $this->licensed()) {
            return null;
        }

        return match (true) {
            $this->authz->allows($user, 'remote.control', $branch) => 'control',
            $this->authz->allows($user, 'remote.view', $branch) => 'view',
            default => null,
        };
    }

    /** What SyncLytics support may do on the branch's devices now: 'control', 'view' or null (no grant in force). */
    public function supportLevel(Branch $branch): ?string
    {
        $levels = SupportAccessGrant::withoutGlobalScopes()->active()->where('tenant_id', $branch->tenant_id)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id))->pluck('level');

        return $levels->contains('control') ? 'control' : ($levels->isNotEmpty() ? 'view' : null);
    }

    /**
     * The owner lets SyncLytics support in: for some hours, or until a problem report is closed.
     *
     * @param  array{level: string, branch_id?: ?string, hours?: ?int, support_report_id?: ?string, reason: string}  $data
     */
    public function grant(Actor $actor, array $data): SupportAccessGrant
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'support.access.grant')) {
            throw new AccessDenied;
        }
        if (! array_key_exists($data['level'] ?? '', SupportAccessGrant::LEVELS)) {
            throw new DomainException('Choose what support may do: see only, or see and control.');
        }
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new DomainException('Say what support is helping with.');
        }
        $report = ($data['support_report_id'] ?? null) ? SupportReport::find($data['support_report_id']) : null;
        $hours = (int) ($data['hours'] ?? 0);
        if (! $report && ($hours < 1 || $hours > 72)) {
            throw new DomainException('Give the access for 1 to 72 hours, or until a problem report is closed.');
        }
        if ($report && $report->status !== 'open') {
            throw new DomainException("Problem {$report->number} is already closed.");
        }
        $branch = ($data['branch_id'] ?? null) ? Branch::findOrFail($data['branch_id']) : null;

        $g = $this->recorder->transaction(function () use ($actor, $data, $reason, $report, $hours, $branch) {
            $g = (new SupportAccessGrant)->forceFill(['tenant_id' => $actor->tenantId, 'branch_id' => $branch?->id, 'level' => $data['level'],
                'starts_at' => now(), 'ends_at' => $report ? null : now()->addHours($hours), 'support_report_id' => $report?->id,
                'reason' => mb_substr($reason, 0, 255), 'granted_by' => $actor->id, 'granted_by_name' => $actor->name]);

            return $this->recorder->save($actor, $g, 'support_access.granted', $reason, $branch?->id);
        });
        $this->tellSupport($g, 'granted');

        return $g;
    }

    public function revoke(Actor $actor, SupportAccessGrant $g): SupportAccessGrant
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'support.access.grant')) {
            throw new AccessDenied;
        }
        if ($g->revoked_at) {
            return $g;
        }
        $g->forceFill(['revoked_at' => now(), 'revoked_by_name' => $actor->name]);
        $g = $this->recorder->transaction(fn () => $this->recorder->save($actor, $g, 'support_access.revoked', branchId: $g->branch_id));
        $this->tellSupport($g, 'withdrawn');

        return $g;
    }

    /** Support learns by e-mail that access was given (and until when) or withdrawn. A mail failure never blocks the owner. */
    private function tellSupport(SupportAccessGrant $g, string $what): void
    {
        $to = SupportReports::address($g->tenant_id);
        if (! $to) {
            return;
        }
        $tenant = Tenant::find($g->tenant_id);
        $until = $g->support_report_id ? 'until problem '.$g->report?->number.' is closed' : 'until '.$g->ends_at?->timezone($tenant->timezone ?: 'UTC')->format('j M Y H:i');
        $text = "{$tenant->name}: support access {$what} by {$g->granted_by_name}".($what === 'withdrawn' ? " ({$g->revoked_by_name})" : '').".\n\n"
            .'Level: '.SupportAccessGrant::LEVELS[$g->level]."\nBranches: ".($g->branch?->name ?? 'all')."\nFor: {$g->reason}\n".($what === 'granted' ? "Valid {$until}.\n" : '');
        try {
            Mail::to($to)->send(new PlainMessage("[{$tenant->name}] Support access {$what}", $text));
        } catch (Throwable) {
            // the grant stands; support also sees it in the vendor console (phase 2)
        }
    }
}
