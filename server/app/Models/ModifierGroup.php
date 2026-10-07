<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Choices offered with a dish ("Cooking: rare / medium / well done", "Extras: cheese +5.00"). */
class ModifierGroup extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'min_choices' => 'integer', 'max_choices' => 'integer'];
    }

    public function options(): HasMany
    {
        return $this->hasMany(ModifierOption::class)->orderBy('sort');
    }
}
