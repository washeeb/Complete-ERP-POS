<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One debit (+) or credit (−) on an account, with the branch as cost centre. */
class JournalLine extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'amount_minor' => 'integer'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
