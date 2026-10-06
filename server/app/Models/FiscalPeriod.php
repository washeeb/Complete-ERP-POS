<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One month of a financial year. Closed months accept no postings (owner decision 2026-10-12). */
class FiscalPeriod extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'closed_at' => 'datetime', 'year' => 'integer', 'period' => 'integer'];
    }

    public function label(): string
    {
        return $this->starts_on->format('M Y');
    }
}
