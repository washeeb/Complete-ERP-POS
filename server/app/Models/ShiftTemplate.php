<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A working shift (Morning, Afternoon, …): each till gets one vault per active shift. */
class ShiftTemplate extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['name', 'starts_at', 'ends_at', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort' => 'integer'];
    }

    public function label(): string
    {
        return $this->name.' ('.substr((string) $this->starts_at, 0, 5).'–'.substr((string) $this->ends_at, 0, 5).')';
    }
}
