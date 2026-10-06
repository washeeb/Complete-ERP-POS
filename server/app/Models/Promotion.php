<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A promotion run by the engine (App\Domain\Pricing\PromotionEngine and the till's promotions.ts). */
class Promotion extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['product_ids' => 'array', 'category_ids' => 'array', 'price_list_ids' => 'array', 'branch_ids' => 'array', 'days' => 'array',
            'value_bp' => 'integer', 'value_minor' => 'integer', 'buy_qty' => 'integer', 'get_qty' => 'integer', 'bundle_qty' => 'integer',
            'min_spend_minor' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean'];
    }

    /** The shape both engines take (and the till caches). */
    public function toEngine(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'type' => $this->type, 'product_ids' => array_values($this->product_ids ?? []),
            'category_ids' => array_values($this->category_ids ?? []), 'value_bp' => $this->value_bp, 'value_minor' => $this->value_minor,
            'buy_qty' => $this->buy_qty, 'get_qty' => $this->get_qty, 'bundle_qty' => $this->bundle_qty, 'min_spend_minor' => $this->min_spend_minor,
            'coupon_code' => $this->coupon_code, 'starts_at' => $this->starts_at?->toIso8601String(), 'ends_at' => $this->ends_at?->toIso8601String(),
            'days' => array_values(array_map('intval', $this->days ?? [])), 'time_from' => $this->time_from ? substr($this->time_from, 0, 5) : null,
            'time_to' => $this->time_to ? substr($this->time_to, 0, 5) : null, 'branch_ids' => array_values($this->branch_ids ?? []),
            'price_list_ids' => array_values($this->price_list_ids ?? [])];
    }
}
