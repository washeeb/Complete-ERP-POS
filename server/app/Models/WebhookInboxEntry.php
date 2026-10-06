<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookInboxEntry extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'webhook_inbox';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['headers' => 'array', 'received_at' => 'datetime', 'processed_at' => 'datetime'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class, 'channel_id');
    }
}
