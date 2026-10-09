<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A sale, invoice or credit note reported to GRA (E-VAT), with GRA's reference and QR content. */
class FiscalSubmission extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUSES = ['pending' => 'Waiting to send', 'submitted' => 'Accepted by GRA', 'rejected' => 'Refused by GRA', 'failed' => 'Failed'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'response' => 'array', 'doc_date' => 'date', 'next_attempt_at' => 'datetime'];
    }
}
