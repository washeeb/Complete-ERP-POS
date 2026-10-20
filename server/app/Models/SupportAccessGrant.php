<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SyncLytics support may reach this company's devices: view or control, for a time or until a problem report closes. */
class SupportAccessGrant extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const LEVELS = ['view' => 'See the screens only', 'control' => 'See and control (mouse and keyboard, remote fixes)'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(SupportReport::class, 'support_report_id');
    }

    /** In force now: started, not withdrawn, not past its end, and its problem report (if any) still open. */
    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('revoked_at')->where('starts_at', '<=', now())
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->where(fn ($w) => $w->whereNull('support_report_id')->orWhereHas('report', fn ($r) => $r->where('status', 'open')));
    }

    public function isActive(): bool
    {
        return self::active()->whereKey($this->id)->exists();
    }
}
