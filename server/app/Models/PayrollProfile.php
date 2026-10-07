<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Statutory rules for payroll (PAYE bands, SSNIT tiers) — accountant-verified. */
class PayrollProfile extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['rules' => 'array', 'effective_from' => 'date', 'verified_at' => 'datetime'];
    }
}
