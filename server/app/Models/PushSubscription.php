<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A browser (phone or computer) that agreed to show this person's notifications (App\Domain\Messaging\PhonePush). */
class PushSubscription extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_sent_at' => 'datetime'];
    }
}
