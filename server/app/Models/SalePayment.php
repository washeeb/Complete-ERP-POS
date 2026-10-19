<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalePayment extends Model
{
    use HasUuids;

    public const METHODS = ['cash' => 'Cash', 'momo' => 'Mobile money', 'card' => 'Card (standalone terminal)', 'account' => 'On account (credit)', 'points' => 'Loyalty points', 'gift_card' => 'Gift card', 'store_credit' => 'Store credit',
        'deposit' => 'Booking deposit', 'aggregator' => 'Delivery app (paid by the platform)', 'trade_in' => 'Trade-in'];

    /** Delivery apps whose orders the restaurant takes (paid to us by the platform later). */
    public const PLATFORMS = ['bolt_food' => 'Bolt Food', 'glovo' => 'Glovo', 'chowdeck' => 'Chowdeck'];

    public const MOMO_PROVIDERS = ['mtn' => 'MTN MoMo', 'telecel' => 'Telecel Cash', 'at' => 'AT Money'];

    /** What a till's close checks besides the cash: each MoMo network's merchant wallet and the card terminal. */
    public const CLOSE_CHECKS = ['momo:mtn' => 'MTN MoMo', 'momo:telecel' => 'Telecel Cash', 'momo:at' => 'AT Money', 'card' => 'Card terminal'];

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'tendered_minor' => 'integer'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
