<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A place backups are copied to (this server only; never synced). Settings and keys are encrypted with this server's key. */
class BackupDestination extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return ['config' => 'encrypted:array', 'is_active' => 'boolean', 'last_ok_at' => 'datetime', 'keep' => 'integer'];
    }
}
