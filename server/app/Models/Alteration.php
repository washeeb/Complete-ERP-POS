<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** An alteration job: a customer's garment taken in for tailoring, at an agreed charge, paid at the till on collection. */
class Alteration extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $guarded = [];

    public const STATUSES = ['received' => 'Received', 'in_progress' => 'In progress', 'ready' => 'Ready to collect', 'collected' => 'Collected', 'cancelled' => 'Cancelled'];

    public const OPEN = ['received', 'in_progress', 'ready'];

    protected function casts(): array
    {
        return ['charge_minor' => 'integer', 'due_on' => 'date', 'received_at' => 'datetime', 'collected_at' => 'datetime'];
    }
}
