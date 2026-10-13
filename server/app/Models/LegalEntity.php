<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LegalEntity extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['name', 'registration_no', 'tin', 'vat_no', 'currency_code', 'address', 'is_primary', 'is_active'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'is_active' => 'boolean'];
    }

    /** The head office company: lines without a branch, and branches without their own entity, belong to it. */
    public static function primary(): ?self
    {
        return self::orderByDesc('is_primary')->orderBy('created_at')->first();
    }
}
