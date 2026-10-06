<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/** A gift card or store credit: a code and a balance kept in an append-only ledger. */
class StoredValue extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const KINDS = ['gift_card' => 'Gift card', 'store_credit' => 'Store credit'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function balanceMinor(): int
    {
        return (int) DB::table('stored_value_balances')->where('stored_value_id', $this->id)->value('balance_minor');
    }
}
