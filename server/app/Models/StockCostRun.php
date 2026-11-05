<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A cost run: products without a cost get one worked out from their selling price. See App\Domain\Inventory\StockCostRuns. */
class StockCostRun extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const METHODS = ['divide' => 'Selling price ÷ a factor (e.g. ÷ 1.4)', 'margin' => 'Selling price less a profit percentage (e.g. 40%)'];

    public const TAX_MODES = ['shelf' => 'Use the price the customer pays', 'remove_tax' => 'Remove VAT and levies first'];

    public const STATUSES = ['draft' => 'Draft', 'submitted' => 'Waiting for approval', 'posted' => 'Posted', 'cancelled' => 'Cancelled', 'reversed' => 'Undone'];

    protected $fillable = [];

    protected function casts(): array
    {
        return ['overrides' => 'array', 'scope' => 'array', 'overwrite' => 'boolean', 'catch_up' => 'boolean', 'effective_on' => 'date', 'value_bp' => 'integer',
            'stock_value_minor' => 'integer', 'catch_up_minor' => 'integer', 'submitted_at' => 'datetime', 'posted_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockCostRunLine::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['draft', 'submitted'], true);
    }

    /** "÷ 1.4" or "less 40%". */
    public static function factorLabel(string $method, int $valueBp): string
    {
        $n = rtrim(rtrim(number_format($valueBp / ($method === 'divide' ? 10000 : 100), 2, '.', ''), '0'), '.');

        return $method === 'divide' ? "÷ {$n}" : "less {$n}%";
    }
}
