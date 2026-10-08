<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** The SMS or WhatsApp provider this server sends through. Credentials are encrypted with this server's key and never synced. */
class MessageProvider extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'is_active' => 'boolean', 'tested_at' => 'datetime'];
    }
}
