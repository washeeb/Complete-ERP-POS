<?php

namespace App\Models\Concerns;

use App\Models\Industry;
use App\Support\IndustryContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Scope;

/**
 * A record that belongs to one industry (owner 2026-11-09: "make sure every industry is independent"): customers,
 * suppliers, categories, brands, promotions, styles, choice groups and campaigns. Every query in the back office and
 * at the till sees only the industries in view (IndustryContext::visibleIds), plus shared records (industry_id null,
 * chosen on purpose where sharing is allowed). Background work and company-wide people under "All industries" see all.
 *
 * Checks that must see every industry (unique codes, duplicates) use withoutIndustryScope().
 */
trait BelongsToIndustry
{
    public static function bootBelongsToIndustry(): void
    {
        static::addGlobalScope('industry', new class implements Scope
        {
            public function apply(Builder $builder, Model $model): void
            {
                $ids = IndustryContext::visibleIds();
                if ($ids !== null) {
                    $column = $model->qualifyColumn('industry_id');
                    $builder->where(fn ($w) => $w->whereNull($column)->orWhereIn($column, $ids));
                }
            }
        });
        static::creating(function (Model $model) {
            if (! array_key_exists('industry_id', $model->getAttributes())) {
                $model->industry_id = IndustryContext::defaultId(); // the chosen industry, or the person's / till's only one
            }
            if ($model->industry_id === IndustryContext::SHARED) {
                $model->industry_id = null;
            }
        });
    }

    public static function withoutIndustryScope(): Builder
    {
        return static::withoutGlobalScope('industry');
    }

    public function industry(): BelongsTo
    {
        return $this->belongsTo(Industry::class);
    }
}
