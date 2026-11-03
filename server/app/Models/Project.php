<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A project (job): for a customer, billed by milestones or for time and materials, or internal (not billed). */
class Project extends Model
{
    use BelongsToTenant, HasUuids;

    public const BILLING = ['milestones' => 'Fixed price, by milestones', 'time' => 'Time and materials', 'internal' => 'Internal (not billed)'];

    public const STATUSES = ['planned' => 'Planned', 'active' => 'In progress', 'on_hold' => 'On hold', 'done' => 'Finished', 'cancelled' => 'Cancelled'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'closed_at' => 'datetime', 'contract_minor' => 'integer', 'budget_cost_minor' => 'integer',
            'hourly_rate_minor' => 'integer', 'materials_markup_bp' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function serviceProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'service_product_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class)->orderBy('sort')->orderBy('created_at');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class)->orderBy('sort')->orderBy('due_on');
    }

    public function costs(): HasMany
    {
        return $this->hasMany(ProjectCost::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['planned', 'active', 'on_hold'], true);
    }

    public function isBilled(): bool
    {
        return $this->billing !== 'internal';
    }
}
