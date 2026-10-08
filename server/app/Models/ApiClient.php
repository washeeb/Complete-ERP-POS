<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/** Another system using the public API; its keys are Sanctum tokens whose abilities are its scopes. */
class ApiClient extends Model
{
    use BelongsToTenant, HasApiTokens, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'is_active' => 'boolean', 'last_used_at' => 'datetime'];
    }
}
