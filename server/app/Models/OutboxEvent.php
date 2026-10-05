<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    use BelongsToTenant;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime', 'published_at' => 'datetime'];
    }
}
