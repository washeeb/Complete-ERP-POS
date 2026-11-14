<?php

namespace App\Domain\Access;

use App\Models\AccessRequest;
use RuntimeException;

/** Not done yet: the change was sent to the owner or SyncLytics support for approval (screens say so; nothing failed). */
final class SentForApproval extends RuntimeException
{
    public function __construct(public readonly AccessRequest $request)
    {
        parent::__construct('Sent for approval: '.$request->summary);
    }
}
