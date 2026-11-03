<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A sale being worked on (CRM), moving along the pipeline until it is won (its order confirmed) or lost. */
class CrmDeal extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUSES = ['open' => 'In progress', 'won' => 'Won', 'lost' => 'Lost'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value_minor' => 'integer', 'expected_close_on' => 'date', 'closed_at' => 'datetime'];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(CrmStage::class, 'stage_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomerOrder::class, 'customer_order_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CrmActivity::class, 'deal_id');
    }

    /** Who the deal is with: the customer, else the lead. */
    public function contactName(): string
    {
        return $this->customer?->name ?? $this->lead?->company_name ?? $this->lead?->name ?? '—';
    }
}
