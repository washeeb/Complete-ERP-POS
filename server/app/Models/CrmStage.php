<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A step of the sales pipeline (CRM): open steps in order, then won and lost. */
class CrmStage extends Model
{
    use Archivable, BelongsToTenant, HasUuids;

    public const KINDS = ['open' => 'In progress', 'won' => 'Won', 'lost' => 'Lost'];

    /** The pipeline a company starts with: name => [probability %, kind]. */
    public const DEFAULTS = ['New' => [10, 'open'], 'Contacted' => [20, 'open'], 'Quotation sent' => [50, 'open'], 'Negotiation' => [75, 'open'],
        'Won' => [100, 'won'], 'Lost' => [0, 'lost']];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['probability' => 'integer', 'sort' => 'integer', 'is_active' => 'boolean'];
    }
}
