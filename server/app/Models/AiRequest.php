<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One call to an AI model: what was sent (masked), what came back, tokens and cost. Append-only. */
class AiRequest extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tools' => 'array', 'created_at' => 'datetime', 'tokens_in' => 'integer', 'tokens_out' => 'integer', 'cost_micro_usd' => 'integer'];
    }
}
