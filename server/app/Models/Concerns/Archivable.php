<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * A record that is archived instead of deleted when it is no longer used (owner 2026-11-08/09): history keeps pointing
 * at it, but it leaves the lists people choose from. Archiving goes through App\Domain\Org\ArchiveService (reason,
 * checks, audit). An archived record with an on/off column is always off, whatever a form or import sends.
 */
trait Archivable
{
    public function initializeArchivable(): void
    {
        $this->mergeCasts(['archived_at' => 'datetime']);
    }

    public static function bootArchivable(): void
    {
        static::saving(function ($model) {
            if ($model->archived_at && array_key_exists('is_active', $model->getAttributes())) {
                $model->is_active = false;
            }
        });
    }

    /** Only those in use (not archived). */
    public function scopeInUse(Builder $q): Builder
    {
        return $q->whereNull($this->qualifyColumn('archived_at'));
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
