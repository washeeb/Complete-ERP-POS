<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A customer's WhatsApp order or prescription photo, waiting for staff (Online → WhatsApp orders). */
class WhatsappOrder extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUSES = ['new' => 'New', 'ordered' => 'Order made', 'declined' => 'Declined'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['media' => 'array', 'message_ids' => 'array', 'received_at' => 'datetime', 'last_message_at' => 'datetime', 'handled_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }
}
