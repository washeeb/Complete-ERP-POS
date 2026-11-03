<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** How fast a ticket of a priority is answered and fixed (minutes, around the clock). */
class ServiceLevel extends Model
{
    use BelongsToTenant, HasUuids;

    /** priority => [label, answer within, fix within] (minutes) the company starts with */
    public const DEFAULTS = ['urgent' => ['Urgent', 60, 240], 'high' => ['High', 240, 1440], 'normal' => ['Normal', 480, 4320], 'low' => ['Low', 1440, 10080]];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['respond_minutes' => 'integer', 'resolve_minutes' => 'integer'];
    }

    /** "4 h", "3 days" */
    public static function span(int $minutes): string
    {
        return match (true) {
            $minutes % 1440 === 0 => ($minutes / 1440).' day(s)',
            $minutes % 60 === 0 => ($minutes / 60).' h',
            default => $minutes.' min',
        };
    }
}
