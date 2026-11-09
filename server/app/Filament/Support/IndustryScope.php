<?php

namespace App\Filament\Support;

use App\Models\Branch;
use App\Support\IndustryContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lists that follow the industry in view (owner 2026-11-09: every industry independent): by their branch, or by the
 * product, customer or supplier they belong to. With no industry in view (company-wide people under "All
 * industries") nothing is filtered.
 */
final class IndustryScope
{
    /** Rows of the branches of the industries in view. Rows without a branch (company-level) show only under All. */
    public static function branches(Builder $query, string $column = 'branch_id'): Builder
    {
        $ids = IndustryContext::visibleIds();
        if ($ids === null) {
            return $query;
        }

        return $query->whereIn($query->qualifyColumn($column), Branch::whereIn('industry_id', $ids)->select('id'));
    }

    /** Rows whose product is in the industries in view (or shared). */
    public static function product(Builder $query, string $relation = 'product'): Builder
    {
        return IndustryContext::visibleIds() === null ? $query : $query->whereHas($relation, fn (Builder $q) => IndustryContext::productScope($q));
    }

    /** Rows whose customer or supplier is in the industries in view (their own industry filter applies). */
    public static function owner(Builder $query, string $relation): Builder
    {
        return IndustryContext::visibleIds() === null ? $query : $query->whereHas($relation);
    }

    /** Journal entries with lines in the industries in view. */
    public static function journal(Builder $query): Builder
    {
        $ids = IndustryContext::visibleIds();

        return $ids === null ? $query : $query->whereExists(fn ($q) => $q->selectRaw('1')->from('journal_lines as jl')
            ->whereColumn('jl.journal_entry_id', $query->qualifyColumn('id'))->whereIn('jl.industry_id', $ids));
    }
}
