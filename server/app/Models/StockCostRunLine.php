<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One product in one store on a cost run, or a product the run could not cost (kind "skipped", with the reason). */
class StockCostRunLine extends Model
{
    use BelongsToTenant, HasUuids;

    public const KINDS = ['estimate' => 'Had no cost', 'overwrite' => 'Replaces a cost', 'top_up' => 'Books brought up to its cost', 'skipped' => 'Skipped'];

    protected $fillable = [];

    protected function casts(): array
    {
        return ['new_cost_minor' => 'integer', 'value_before_minor' => 'integer', 'stock_value_minor' => 'integer', 'catch_up_minor' => 'integer', 'value_bp' => 'integer'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(StockCostRun::class, 'stock_cost_run_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
