<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An account in the chart of accounts. Header accounts group others; control accounts belong to a sub-ledger. */
class Account extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const TYPES = ['asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expense'];

    public const CONTROLS = ['receivables' => 'Customer accounts (receivables)', 'payables' => 'Supplier accounts (payables)',
        'inventory' => 'Stock', 'stored_value' => 'Gift cards and store credit', 'loyalty' => 'Loyalty points', 'intercompany' => 'Due between group companies (automatic)'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_header' => 'boolean', 'is_active' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Balance-sheet accounts carry their balance forward; income and expense start each year at zero. */
    public function isBalanceSheet(): bool
    {
        return in_array($this->type, ['asset', 'liability', 'equity'], true);
    }

    public function label(): string
    {
        return "{$this->code} {$this->name}";
    }
}
