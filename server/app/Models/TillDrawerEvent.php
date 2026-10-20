<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Something done to a till's drawer besides a sale (append-only; the database refuses changes). */
class TillDrawerEvent extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const UPDATED_AT = null;

    public const KINDS = ['payout' => 'Paid out (expense)', 'payin' => 'Paid in', 'drop' => 'Cash drop to the safe', 'no_sale' => 'Drawer opened, no sale', 'limit_alert' => 'Cash above the limit'];

    protected $fillable = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'occurred_at' => 'datetime', 'offline' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
