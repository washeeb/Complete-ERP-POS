<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['code', 'name', 'contact_name', 'phone', 'email', 'tax_no', 'address', 'payment_terms_days', 'currency_code', 'lead_time_days', 'return_days_before_expiry', 'is_active', 'notes'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'payment_terms_days' => 'integer', 'lead_time_days' => 'integer', 'return_days_before_expiry' => 'integer'];
    }

    public function products(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }
}
