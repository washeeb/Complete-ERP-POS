<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A fix queued for a device in the back office; the till runs it at its next health check and reports back. */
class DeviceCommand extends Model
{
    use BelongsToTenant, HasUuids;

    public const COMMANDS = [
        'reload' => 'Reload the till',
        'sync_now' => 'Send waiting sales now',
        'refresh_catalog' => 'Refresh the catalogue',
        'clear_cache' => 'Clear the till cache (catalogue and pictures; never unsent sales)',
        'collect_logs' => 'Collect logs (recent errors and the queue)',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'done_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
