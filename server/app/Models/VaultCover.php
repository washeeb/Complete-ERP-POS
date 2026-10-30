<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A manager lends a teller's vault to someone else for one day (the holder is away, or cover). */
class VaultCover extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['business_date' => 'date'];
    }

    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }
}
