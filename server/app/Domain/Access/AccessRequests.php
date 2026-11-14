<?php

namespace App\Domain\Access;

use App\Domain\Messaging\Notifier;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Filament\Resources\AccessRequests\AccessRequestResource;
use App\Models\AccessRequest;
use App\Models\Role;
use App\Models\User;
use App\Security\ScopeType;
use Carbon\Carbon;
use Filament\Notifications\Notification;

/**
 * Access changes by IT Admin go to the owner or SyncLytics support first (owner decision 2026-11-13: "IT asks,
 * Superadmin or owner approves, but Superadmin does it, owner is alerted"). Adding a user, changing someone's sign-in
 * details or level, giving access, changing a role, setting someone's till PIN and resetting their two-step sign-in wait
 * for a yes; taking access away is done at once (the safe direction). On approval the change runs with the approver's
 * authority: handing out business power needs someone who holds it, which IT Admin does not. Unanswered requests lapse
 * after 72 hours.
 */
final class AccessRequests
{
    public function __construct(private readonly Recorder $recorder, private readonly Notifier $notifier) {}

    /** Someone whose access changes need a yes: anyone but the owner and SyncLytics support. */
    public static function needsApproval(Actor $actor): bool
    {
        return $actor->user !== null && ! $actor->user->isSupport() && ! OwnerSeparation::isOwnerAccount($actor->user);
    }

    /** Who may say yes: the owner, or SyncLytics support; never the person who asked. */
    public static function mayDecide(User $user, AccessRequest $r): bool
    {
        return $user->id !== $r->requested_by && $user->tenant_id === $r->tenant_id && ($user->isSupport() || OwnerSeparation::isOwnerAccount($user));
    }

    public function submit(Actor $actor, string $kind, array $payload, string $summary, ?User $subject = null): AccessRequest
    {
        if (! isset(AccessRequest::KINDS[$kind])) {
            throw new DomainException('Unknown kind of access change.');
        }
        if ($subject && $subject->id === $actor->id) {
            throw new DomainException('You cannot ask for a change to your own account or access. Ask the owner or SyncLytics support.');
        }
        $r = new AccessRequest(['kind' => $kind, 'subject_user_id' => $subject?->id, 'payload' => $payload, 'summary' => mb_substr($summary, 0, 2000),
            'requested_by' => $actor->id, 'requested_by_name' => $actor->name, 'status' => 'pending', 'expires_at' => now()->addHours(AccessRequest::HOURS)]);
        $r->tenant_id = $actor->tenantId;
        $this->recorder->save($actor, $r, 'access_request.submitted', $summary);
        $this->notifier->toPermission($actor->tenantId, 'settings.business', null, 'Access change waiting for your approval',
            "{$actor->name} asks: {$summary} It lapses in ".AccessRequest::HOURS.' hours if not answered.', AccessRequestResource::getUrl('index'), [$actor->id], topic: 'security');

        return $r;
    }

    /** Yes: the change is made now, with the approver's authority. A change that can no longer be made is marked failed, with why. */
    public function approve(Actor $approver, AccessRequest $r, ?string $note = null): AccessRequest
    {
        $this->assertDecidable($approver, $r);
        $svc = app(AccessService::class);
        try {
            $this->recorder->transaction(function () use ($svc, $approver, $r, $note) {
                $svc->asApproved($r, fn () => $this->perform($svc, $approver, $r));
                $this->decide($approver, $r, 'approved', $note);
            });
        } catch (DomainException|AccessDenied $e) {
            $this->decide($approver, $r, 'failed', mb_substr($e->getMessage(), 0, 250));
            $this->tellRequester($r, 'Your access request could not be done', "{$r->summary} Approved by {$approver->name}, but: {$e->getMessage()}");

            return $r->fresh();
        }
        $this->tellRequester($r, 'Your access request was approved', "{$r->summary} Approved by {$approver->name}.");

        return $r->fresh();
    }

    public function reject(Actor $approver, AccessRequest $r, string $note): AccessRequest
    {
        $this->assertDecidable($approver, $r);
        if (trim($note) === '') {
            throw new DomainException('Say why (the person who asked is told).');
        }
        $this->decide($approver, $r, 'rejected', $note);
        $this->tellRequester($r, 'Your access request was turned down', "{$r->summary} {$approver->name}: {$note}");

        return $r->fresh();
    }

    public function withdraw(Actor $actor, AccessRequest $r): AccessRequest
    {
        if ($r->requested_by !== $actor->id || ! $r->isWaiting()) {
            throw new DomainException('Only a waiting request can be withdrawn, by the person who asked.');
        }
        $this->decide($actor, $r, 'withdrawn', null);

        return $r->fresh();
    }

    /** Requests past their 72 hours lapse; the person who asked is told. Returns how many. */
    public function expire(string $tenantId): int
    {
        $n = 0;
        foreach (AccessRequest::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('status', 'pending')->where('expires_at', '<=', now())->get() as $r) {
            $actor = Actor::system($tenantId, 'Access requests', 'system');
            $this->decide($actor, $r, 'expired', 'Not answered in '.AccessRequest::HOURS.' hours');
            $this->tellRequester($r, 'Your access request lapsed', "{$r->summary} Nobody answered in ".AccessRequest::HOURS.' hours. Ask again if it is still needed.');
            $n++;
        }

        return $n;
    }

    private function perform(AccessService $svc, Actor $approver, AccessRequest $r): void
    {
        $p = $r->payload;
        $subject = fn () => User::withoutGlobalScopes()->where('tenant_id', $r->tenant_id)->findOrFail($r->subject_user_id);
        match ($r->kind) {
            'user.create' => $svc->createUser($approver, $p),
            'user.update' => $svc->updateUser($approver, $subject(), $p['data'], $p['reason'] ?? null),
            'access.grant' => $svc->grant($approver, $subject(), Role::findOrFail($p['role_id']), ScopeType::from($p['scope_type']), $p['scope_id'],
                $p['limits'] ?? [], filled($p['expires_at'] ?? null) ? Carbon::parse($p['expires_at']) : null, $p['reason'] ?? null),
            'role.save' => $svc->saveRole($approver, filled($p['role_id'] ?? null) ? Role::findOrFail($p['role_id']) : new Role, $p['attributes'], $p['permissions'], $p['reason'] ?? null),
            'user.pin' => $svc->setPin($approver, $subject(), $p['pin_hash'], hashed: true),
            'user.reset_mfa' => $svc->resetMfa($approver, $subject(), $p['reason']),
        };
    }

    private function assertDecidable(Actor $approver, AccessRequest $r): void
    {
        if (! $approver->user || ! self::mayDecide($approver->user, $r)) {
            throw new AccessDenied('Only the owner or SyncLytics support decides access requests, and never their own.');
        }
        if (! $r->isWaiting()) {
            throw new DomainException('This request is no longer waiting ('.(AccessRequest::STATUSES[$r->status] ?? $r->status).').');
        }
    }

    private function decide(Actor $actor, AccessRequest $r, string $status, ?string $note): void
    {
        $r->forceFill(['status' => $status, 'decided_by' => $actor->id, 'decided_by_name' => $actor->name, 'decided_at' => now(), 'decision_note' => $note]);
        $this->recorder->save($actor, $r, "access_request.{$status}", $note);
    }

    private function tellRequester(AccessRequest $r, string $title, string $body): void
    {
        $user = User::withoutGlobalScopes()->find($r->requested_by);
        if ($user) {
            Notification::make()->title($title)->body($body)->sendToDatabase($user);
        }
    }
}
