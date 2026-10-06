<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelJob extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['next_attempt_at' => 'datetime', 'attempts' => 'integer'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class, 'channel_id');
    }
}
