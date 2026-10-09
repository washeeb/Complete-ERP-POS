<?php

namespace App\Models;

use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasOriginNode, HasUuids;

    protected $fillable = ['name', 'slug', 'country_code', 'currency_code', 'locale', 'timezone', 'deployment_mode'];

    protected function casts(): array
    {
        return ['setup_completed_at' => 'datetime', 'is_practice' => 'boolean'];
    }

    /** @var array<string, bool> */
    private static array $practice = [];

    /** A practice (training) company: nothing it does is sent to GRA, customers, online stores or other systems. */
    public static function isPractice(?string $tenantId): bool
    {
        if ($tenantId === null) {
            return false;
        }

        return self::$practice[$tenantId] ??= (bool) static::whereKey($tenantId)->value('is_practice');
    }

    public static function forgetPractice(): void
    {
        self::$practice = [];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function isSetUp(): bool
    {
        return $this->setup_completed_at !== null;
    }
}
