<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A part of a fixed-price project's price, invoiced when it is reached (e.g. deposit, delivery, handover). */
class ProjectMilestone extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUSES = ['pending' => 'Not yet', 'ready' => 'Ready to invoice', 'invoiced' => 'Invoiced'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'amount_minor' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
