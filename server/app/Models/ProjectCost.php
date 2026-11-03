<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A cost a project collected: materials from stock, a supplier bill, an expense claim or approved time. */
class ProjectCost extends Model
{
    use BelongsToTenant, HasUuids;

    public const KINDS = ['material' => 'Materials from stock', 'purchase' => 'Bought in (supplier bills)', 'expense' => 'Expenses', 'time' => 'Staff time'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['cost_minor' => 'integer', 'bill_minor' => 'integer', 'billable' => 'boolean', 'in_books' => 'boolean', 'occurred_on' => 'date', 'released_at' => 'datetime'];
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
