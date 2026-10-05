<?php

namespace App\Domain\Sync;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\ReviewItem;
use App\Security\Authorizer;

/** The human conflict queue (spec §4.2): anything sync cannot or must not decide by itself. */
final class ReviewQueue
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz) {}

    public function add(Actor $actor, string $type, string $subjectType, ?string $subjectId, string $summary, array $details = [], ?string $branchId = null): ReviewItem
    {
        $item = new ReviewItem([
            'tenant_id' => $actor->tenantId, 'branch_id' => $branchId, 'type' => $type, 'subject_type' => $subjectType,
            'subject_id' => $subjectId, 'summary' => mb_substr($summary, 0, 255), 'details' => $details, 'status' => 'open',
        ]);

        return $this->recorder->save($actor, $item, 'review.opened', null, $branchId);
    }

    public function resolve(Actor $actor, ReviewItem $item, string $resolution): ReviewItem
    {
        $branch = $item->branch_id ? Branch::find($item->branch_id) : null;
        if (! $actor->user || ! $this->authz->allows($actor->user, 'sync.review', $branch)) {
            throw new AccessDenied;
        }
        if ($item->status !== 'open') {
            throw new DomainException('This item is already resolved.');
        }
        if (trim($resolution) === '') {
            throw new DomainException('Say what was checked or done.');
        }
        $item->forceFill(['status' => 'resolved', 'resolved_by' => $actor->id, 'resolved_by_name' => $actor->name,
            'resolved_at' => now(), 'resolution' => mb_substr($resolution, 0, 255)]);

        return $this->recorder->save($actor, $item, 'review.resolved', $resolution, $item->branch_id);
    }
}
