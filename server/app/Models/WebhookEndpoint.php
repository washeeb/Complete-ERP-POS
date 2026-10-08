<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** An address that receives signed event notifications (public API webhooks). */
class WebhookEndpoint extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['events' => 'array', 'secret' => 'encrypted', 'is_active' => 'boolean'];
    }
}
