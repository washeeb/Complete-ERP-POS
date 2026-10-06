<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A bank, mobile money or cash account the company holds, tied to its general-ledger account. */
class BankAccount extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const KINDS = ['bank' => 'Bank', 'momo' => 'Mobile money', 'cash' => 'Cash / petty cash'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['csv_mapping' => 'array', 'is_active' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
