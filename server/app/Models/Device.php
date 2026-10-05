<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;

class Device extends Model
{
    use BelongsToTenant, HasApiTokens, HasUuids;

    public const KINDS = ['pos' => 'POS till', 'handheld' => 'Handheld scanner', 'kiosk' => 'Self-checkout kiosk', 'kds' => 'Kitchen display'];

    protected $fillable = ['branch_id', 'till_id', 'name', 'kind'];

    protected $hidden = ['enrollment_code_hash', 'public_key'];

    protected function casts(): array
    {
        return [
            'enrollment_expires_at' => 'datetime',
            'enrolled_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function till(): BelongsTo
    {
        return $this->belongsTo(Till::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
