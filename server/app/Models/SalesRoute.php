<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A sales route: customers a rep visits in order, on given weekdays, usually from one van. (Table "routes".) */
class SalesRoute extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    protected $table = 'routes';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['days' => 'array', 'is_active' => 'boolean'];
    }

    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class, 'route_id')->orderBy('sequence');
    }
}
