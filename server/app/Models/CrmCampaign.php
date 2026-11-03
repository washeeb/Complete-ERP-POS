<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One message to many customers (CRM): only to those who agreed to that channel. */
class CrmCampaign extends Model
{
    use BelongsToTenant, HasUuids;

    public const CHANNELS = ['sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'email' => 'E-mail'];

    public const STATUSES = ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'sending' => 'Sending', 'sent' => 'Sent', 'cancelled' => 'Cancelled'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['audience' => 'array', 'send_at' => 'datetime', 'sent_at' => 'datetime', 'recipients' => 'integer', 'sent' => 'integer', 'failed' => 'integer', 'skipped' => 'integer'];
    }

    public function recipientRows(): HasMany
    {
        return $this->hasMany(CrmCampaignRecipient::class, 'campaign_id');
    }
}
