<?php

namespace App\Models;

use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Model;

/** The licence this installation runs under (installation-wide, not per company). */
class License extends Model
{
    use HasOriginNode;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'activated_at' => 'datetime', 'last_checkin_at' => 'datetime', 'revoked_at' => 'datetime', 'is_current' => 'boolean'];
    }
}
