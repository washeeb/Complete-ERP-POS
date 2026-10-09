<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One backup: when, how big, its checksum, which destinations got it, and the last check of it. */
class BackupRun extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['destinations' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'verified_at' => 'datetime', 'size_bytes' => 'integer'];
    }
}
