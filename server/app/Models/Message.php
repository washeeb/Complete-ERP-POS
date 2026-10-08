<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One SMS, WhatsApp message or e-mail and how its delivery went. */
class Message extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUSES = ['queued' => 'Waiting to send', 'sent' => 'Sent', 'failed' => 'Failed', 'not_configured' => 'Not sent (no provider set up)'];

    public const CHANNELS = ['sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'email' => 'E-mail'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['params' => 'array', 'next_attempt_at' => 'datetime', 'sent_at' => 'datetime'];
    }
}
