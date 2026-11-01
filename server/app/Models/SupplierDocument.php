<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A supplier's document on file (certificate, licence, bank letter), with its expiry and who checked it. */
class SupplierDocument extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    /** Kinds of document; the ones a company requires are chosen in Settings → Purchasing. */
    public const KINDS = [
        'tin_certificate' => 'TIN certificate (GRA)',
        'vat_certificate' => 'VAT registration certificate',
        'business_registration' => 'Business registration (ORC)',
        'fda_licence' => 'FDA licence',
        'pharmacy_council' => 'Pharmacy Council licence (wholesale)',
        'wht_exemption' => 'Withholding tax exemption certificate',
        'bank_letter' => 'Bank letter / account confirmation',
        'ssnit_clearance' => 'SSNIT clearance',
        'tax_clearance' => 'Tax clearance certificate',
        'other' => 'Other',
    ];

    protected $fillable = [];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'expires_on' => 'date', 'verified_at' => 'datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function label(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function expired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->isPast() && ! $this->expires_on->isToday();
    }
}
