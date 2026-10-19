<?php

namespace App\Models\Vendor;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** A licence key issued to a customer installation, with its package, dates, price and history. */
class VendorLicense extends Model
{
    use HasUuids;

    protected $table = 'vendor_licenses';

    protected $guarded = [];

    public const KINDS = ['paid' => 'Paid', 'trial' => 'Trial', 'internal' => 'Internal / demo'];

    public const STATUSES = ['active' => 'Active', 'expiring' => 'Expiring soon', 'grace' => 'In grace period', 'expired' => 'Expired',
        'revoked' => 'Revoked', 'replaced' => 'Replaced (renewed or changed)'];

    /** The customer record (the column "customer" is the name signed into the key). */
    public function client(): BelongsTo
    {
        return $this->belongsTo(VendorCustomer::class, 'customer_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(VendorPlan::class, 'plan_id');
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(VendorCheckin::class, 'license_id')->orderByDesc('created_at');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(VendorInvoice::class, 'license_id');
    }

    /** Where the licence stands today (revoked and replaced first; then by expiry and the grace days in the key). */
    public function status(): string
    {
        if ($this->revoked_at) {
            return 'revoked';
        }
        if ($this->superseded_by) {
            return 'replaced';
        }
        $expires = $this->expires_on ?? (isset($this->payload['expires_at']) ? Carbon::parse($this->payload['expires_at']) : null);
        if (! $expires) {
            return 'active';
        }
        $today = now()->startOfDay();
        $grace = (int) ($this->payload['grace_days'] ?? 14);

        return match (true) {
            $expires->copy()->addDays($grace)->lt($today) => 'expired',
            $expires->lt($today) => 'grace',
            $expires->lte($today->copy()->addDays(60)) => 'expiring',
            default => 'active',
        };
    }

    public function daysLeft(): ?int
    {
        return $this->expires_on ? (int) now()->startOfDay()->diffInDays($this->expires_on, false) : null;
    }

    /** The short reference shown to customers (first 8 characters of the id). */
    public function short(): string
    {
        return strtoupper(substr(str_replace('-', '', (string) $this->id), 0, 8));
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'addons' => 'array', 'starts_on' => 'date', 'expires_on' => 'date', 'revoked_at' => 'datetime', 'last_checkin_at' => 'datetime', 'price_minor' => 'integer'];
    }
}
