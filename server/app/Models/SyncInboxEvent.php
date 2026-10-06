<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncInboxEvent extends Model
{
    use BelongsToTenant;

    protected $table = 'sync_inbox';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime', 'received_at' => 'datetime', 'applied_at' => 'datetime', 'origin_seq' => 'integer'];
    }

    public function peer(): BelongsTo
    {
        return $this->belongsTo(SyncPeer::class, 'peer_id');
    }
}
