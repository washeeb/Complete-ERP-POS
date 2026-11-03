<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Someone who may buy (CRM): worked until they become a customer (converted) or are let go (lost). */
class CrmLead extends Model
{
    use BelongsToTenant, HasUuids;

    public const SOURCES = ['walk_in' => 'Walked in', 'phone' => 'Phone call', 'whatsapp' => 'WhatsApp', 'website' => 'Website', 'referral' => 'Referred by someone',
        'event' => 'Event or fair', 'social' => 'Social media', 'other' => 'Other'];

    public const STATUSES = ['new' => 'New', 'contacted' => 'Contacted', 'qualified' => 'Interested', 'converted' => 'Became a customer', 'lost' => 'Lost'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value_minor' => 'integer', 'consents' => 'array'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CrmActivity::class, 'lead_id');
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, ['converted', 'lost'], true);
    }

    public function hasConsent(string $channel): bool
    {
        return (bool) ($this->consents[$channel]['granted'] ?? false);
    }
}
