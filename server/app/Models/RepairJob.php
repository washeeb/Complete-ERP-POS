<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A repair job card: a customer's device in for repair (in or out of warranty), its parts and labour, and its progress. */
class RepairJob extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $guarded = [];

    public const STATUSES = ['received' => 'Received', 'diagnosing' => 'Diagnosing', 'waiting_parts' => 'Waiting for parts', 'repairing' => 'Repairing',
        'at_supplier' => 'Sent to supplier', 'ready' => 'Ready to collect', 'collected' => 'Collected', 'cancelled' => 'Cancelled'];

    public const OPEN = ['received', 'diagnosing', 'waiting_parts', 'repairing', 'at_supplier', 'ready'];

    protected function casts(): array
    {
        return ['in_warranty' => 'boolean', 'estimate_minor' => 'integer', 'promised_on' => 'date', 'received_at' => 'datetime', 'ready_at' => 'datetime',
            'collected_at' => 'datetime', 'log' => 'array'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RepairJobLine::class);
    }
}
