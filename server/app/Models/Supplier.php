<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Models\Concerns\BelongsToIndustry;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use Archivable, BelongsToIndustry, BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['industry_id', 'code', 'name', 'contact_name', 'phone', 'email', 'tax_no', 'address', 'payment_terms_days', 'currency_code', 'lead_time_days', 'return_days_before_expiry', 'is_active', 'notes',
        'supply_kind', 'bank_name', 'bank_branch', 'bank_account_name', 'bank_account_no', 'momo_number'];

    public const STATUSES = ['pending' => 'Waiting for approval', 'approved' => 'Approved', 'blocked' => 'Blocked'];

    /** What they supply: sets the withholding tax rate on payments to them. */
    public const SUPPLY_KINDS = ['goods' => 'Goods', 'services' => 'Services', 'works' => 'Works (construction)'];

    /** The fields whose change sends the bank details back for verification. */
    public const BANK_FIELDS = ['bank_name', 'bank_branch', 'bank_account_name', 'bank_account_no', 'momo_number'];

    protected $hidden = ['portal_token']; // the supplier's secret portal link stays out of logs and snapshots

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'payment_terms_days' => 'integer', 'lead_time_days' => 'integer', 'return_days_before_expiry' => 'integer',
            'approved_at' => 'datetime', 'bank_verified_at' => 'datetime', 'portal_sent_at' => 'datetime'];
    }

    public function products(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(SupplierDocument::class);
    }

    public function hasBankDetails(): bool
    {
        return collect(self::BANK_FIELDS)->contains(fn ($f) => filled($this->{$f}));
    }
}
