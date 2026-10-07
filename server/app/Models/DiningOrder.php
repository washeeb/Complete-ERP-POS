<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An open bill: a table, a bar tab, a counter order or a delivery. Paid by one or more till sales. */
class DiningOrder extends Model
{
    use BelongsToTenant, HasOriginNode;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public const KINDS = ['table' => 'Table', 'tab' => 'Bar tab', 'counter' => 'Counter', 'delivery' => 'Delivery'];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime', 'covers' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(DiningOrderItem::class)->orderBy('added_at');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    public function label(): string
    {
        return match ($this->kind) {
            'table' => 'Table '.($this->table?->code ?? '?'),
            'tab' => 'Tab '.$this->tab_name,
            default => ucfirst($this->kind).' '.$this->number,
        };
    }
}
