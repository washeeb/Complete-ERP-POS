<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A follow-up to do (with a due time) or something that happened (CRM), on a lead, a deal or a customer. */
class CrmActivity extends Model
{
    use BelongsToTenant, HasUuids;

    public const TYPES = ['call' => 'Call', 'meeting' => 'Meeting or visit', 'task' => 'Task', 'message' => 'Message (WhatsApp, SMS, e-mail)', 'note' => 'Note'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'done_at' => 'datetime', 'reminded_at' => 'datetime'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(CrmDeal::class, 'deal_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** What it is about: the deal, the lead or the customer. */
    public function about(): string
    {
        return match (true) {
            $this->deal_id !== null => trim($this->deal?->number.' '.$this->deal?->title),
            $this->lead_id !== null => $this->lead?->name.($this->lead?->company_name ? ' ('.$this->lead->company_name.')' : ''),
            default => (string) $this->customer?->name,
        };
    }
}
