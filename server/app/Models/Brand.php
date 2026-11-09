<?php

namespace App\Models;

use App\Models\Concerns\BelongsToIndustry;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    use BelongsToIndustry, BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['industry_id', 'name'];

    protected function casts(): array
    {
        return [];
    }
}
