<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LegalEntity extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['name', 'registration_no', 'tin', 'vat_no', 'currency_code', 'address', 'is_primary', 'is_active'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'is_active' => 'boolean', 'archived_at' => 'datetime'];
    }

    /** Legal entities to choose from: those in use, plus the one already on the record (see Branch::choices). */
    public static function choices(mixed $keep = null, bool $withHeadOffice = true): array
    {
        $keep = array_values(array_filter((array) $keep, fn ($v) => is_string($v) && $v !== ''));

        return self::query()->where(fn ($q) => $q->where('is_active', true)->when(! $withHeadOffice, fn ($q) => $q->where('is_primary', false))
            ->when($keep, fn ($q) => $q->orWhereIn('id', $keep)))
            ->orderByDesc('is_primary')->orderBy('name')->get()
            ->mapWithKeys(fn (self $e) => [$e->id => $e->name.($e->archived_at ? ' (archived)' : '')])->all();
    }

    /** The head office company: lines without a branch, and branches without their own entity, belong to it. */
    public static function primary(): ?self
    {
        return self::orderByDesc('is_primary')->orderBy('created_at')->first();
    }
}
