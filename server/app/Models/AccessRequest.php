<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An access change asked for by IT Admin, waiting for the owner or SyncLytics support (see App\Domain\Access\AccessRequests). */
class AccessRequest extends Model
{
    use BelongsToTenant, HasUuids;

    public const HOURS = 72;

    public const STATUSES = ['pending' => 'Waiting for approval', 'approved' => 'Approved and done', 'rejected' => 'Turned down',
        'withdrawn' => 'Withdrawn', 'expired' => 'Lapsed (not answered in 72 hours)', 'failed' => 'Approved, but could not be done'];

    public const KINDS = ['user.create' => 'Add a user', 'user.update' => 'Change a user', 'access.grant' => 'Give access',
        'role.save' => 'Change a role', 'user.pin' => 'Set a till PIN', 'user.reset_mfa' => 'Reset two-step sign-in'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'decided_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /** Still waiting, and not past its 72 hours. */
    public function scopeWaiting(Builder $q): Builder
    {
        return $q->where('status', 'pending')->where('expires_at', '>', now());
    }

    public function isWaiting(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture();
    }
}
