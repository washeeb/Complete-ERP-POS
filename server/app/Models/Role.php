<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['key', 'name', 'description', 'security_level', 'is_technical', 'default_limits'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_technical' => 'boolean',
            'security_level' => 'integer',
            'default_limits' => 'array',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role', 'role_id', 'permission_key');
    }

    /** @return list<string> */
    public function permissionKeys(): array
    {
        return $this->permissions()->pluck('key')->all();
    }
}
