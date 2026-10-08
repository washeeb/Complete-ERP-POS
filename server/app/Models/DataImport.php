<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One CSV upload: checked (dry run) or imported, with its row results. Kept on the server where it was done. */
class DataImport extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUSES = ['checked' => 'Checked (not imported)', 'imported' => 'Imported', 'failed' => 'Not imported (errors)'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['options' => 'array', 'summary' => 'array', 'errors' => 'array', 'completed_at' => 'datetime',
            'rows_total' => 'integer', 'rows_ok' => 'integer', 'rows_error' => 'integer'];
    }
}
