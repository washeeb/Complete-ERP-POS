<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A request or complaint on the service desk, with its deadlines and conversation. */
class Ticket extends Model
{
    use BelongsToTenant, HasUuids;

    public const KINDS = ['complaint' => 'Complaint', 'return' => 'Return or refund', 'warranty' => 'Warranty claim', 'support' => 'Question or help',
        'request' => 'Request', 'internal' => 'Staff request (inside the company)'];

    public const SOURCES = ['whatsapp' => 'WhatsApp', 'email' => 'E-mail', 'web' => 'Web form', 'phone' => 'Phone call', 'counter' => 'At the counter',
        'staff' => 'Staff', 'sensor' => 'Sensor alarm'];

    public const PRIORITIES = ['urgent' => 'Urgent', 'high' => 'High', 'normal' => 'Normal', 'low' => 'Low'];

    public const STATUSES = ['new' => 'New', 'open' => 'Being handled', 'waiting' => 'Waiting for them', 'resolved' => 'Resolved', 'closed' => 'Closed'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['respond_by' => 'datetime', 'resolve_by' => 'datetime', 'first_response_at' => 'datetime', 'resolved_at' => 'datetime', 'paused_at' => 'datetime',
            'warned_at' => 'datetime', 'escalated_at' => 'datetime', 'under_warranty' => 'boolean', 'rating' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MaintenanceAsset::class, 'asset_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('created_at')->orderBy('id'); // ids are time-ordered: same-second messages keep their order
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['new', 'open', 'waiting'], true);
    }

    /** The next deadline still running: answering, then fixing; null once resolved or while waiting. */
    public function deadline(): ?CarbonInterface
    {
        if (! in_array($this->status, ['new', 'open'], true)) {
            return null;
        }

        return $this->first_response_at ? $this->resolve_by : ($this->respond_by ?? $this->resolve_by);
    }

    public function late(): bool
    {
        return (bool) $this->deadline()?->isPast();
    }
}
