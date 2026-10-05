<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use App\Security\ScopeType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoleAssignment extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['user_id', 'role_id', 'scope_type', 'scope_id', 'limits', 'starts_at', 'expires_at', 'reason'];

    protected function casts(): array
    {
        return [
            'scope_type' => ScopeType::class,
            'limits' => 'array',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function scopeLabel(): string
    {
        $name = match ($this->scope_type) {
            ScopeType::Tenant => null,
            ScopeType::LegalEntity => LegalEntity::find($this->scope_id)?->name,
            ScopeType::Branch => Branch::find($this->scope_id)?->name,
            ScopeType::Warehouse => Warehouse::find($this->scope_id)?->name,
            ScopeType::Till => Till::find($this->scope_id)?->name,
        };

        return $this->scope_type->label().($name ? ": {$name}" : '');
    }
}
