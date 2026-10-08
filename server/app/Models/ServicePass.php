<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A customer's prepaid sessions (from a pack sold at the till or a membership period), counted down by uses. */
class ServicePass extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array', 'valid_from' => 'date', 'expires_on' => 'date'];
    }

    public const STATUSES = ['active' => 'Active', 'void' => 'Void'];

    public function uses(): HasMany
    {
        return $this->hasMany(ServicePassUse::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
