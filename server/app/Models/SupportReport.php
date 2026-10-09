<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A problem reported by a member of staff from the back office or a till. */
class SupportReport extends Model
{
    use BelongsToTenant, HasUuids;

    public const SOURCES = ['back_office' => 'Back office', 'till' => 'Till'];

    public const STATUSES = ['open' => 'Open', 'closed' => 'Closed'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['context' => 'array', 'closed_at' => 'datetime', 'sent_at' => 'datetime'];
    }
}
