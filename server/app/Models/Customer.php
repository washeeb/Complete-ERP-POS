<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A customer. Synced to every branch; edits merge field by field (last change wins, conflicts logged).
 * Credit fields are head office's alone.
 */
class Customer extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const TYPES = ['individual' => 'Individual', 'business' => 'Business'];

    public const CONSENT_CHANNELS = ['sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'email' => 'Email'];

    /** Fields that merge one by one between nodes (everything a person edits on the profile). */
    public const MERGED_FIELDS = ['type', 'name', 'contact_name', 'phone', 'email', 'tax_no', 'address', 'price_list_id', 'home_branch_id',
        'consents', 'tags', 'notes', 'is_active', 'credit_limit_minor', 'payment_terms_days', 'credit_hold'];

    /** Set at head office only (spec §4.2). */
    public const CREDIT_FIELDS = ['credit_limit_minor', 'payment_terms_days', 'credit_hold'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['consents' => 'array', 'tags' => 'array', 'field_stamps' => 'array', 'is_active' => 'boolean', 'credit_hold' => 'boolean',
            'credit_limit_minor' => 'integer', 'payment_terms_days' => 'integer'];
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function homeBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'home_branch_id');
    }

    public function balance(): HasOne
    {
        return $this->hasOne(CustomerBalance::class);
    }

    public function balanceMinor(): int
    {
        return (int) ($this->balance?->balance_minor ?? 0);
    }

    public function hasConsent(string $channel): bool
    {
        return (bool) ($this->consents[$channel]['granted'] ?? false);
    }
}
