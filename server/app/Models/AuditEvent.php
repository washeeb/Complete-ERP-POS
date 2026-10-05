<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Append-only (DB trigger). Written only by App\Domain\Support\AuditLog. */
class AuditEvent extends Model
{
    use BelongsToTenant;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['changes' => 'array', 'occurred_at' => 'datetime', 'received_at' => 'datetime', 'seq' => 'integer'];
    }
}
