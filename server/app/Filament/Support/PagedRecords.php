<?php

namespace App\Filament\Support;

use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Long lists built in code (What to order, Suggested levels, Expiry & returns…) shown a page at a time (owner request
 * 2026-10-23): pages open quickly, the row buttons are near, and ticks made on one page stay when moving to another —
 * the bulk buttons act on every ticked row of every page (and on "select all" across pages).
 *
 * The page gives allRows(): every row for the current filters and search, keyed by the row key. It is worked out once
 * per request however many times the table asks for it.
 */
trait PagedRecords
{
    /** @var array<string, Collection> rows per filters + search, this request only (not kept between requests) */
    private array $pagedRowsCache = [];

    /** @return Collection<string, array> */
    abstract protected function allRows(?array $filters, ?string $search): Collection;

    protected function cachedRows(?array $filters, ?string $search = null): Collection
    {
        $key = md5(json_encode([$filters, $search]));

        return $this->pagedRowsCache[$key] ??= $this->allRows($filters, $search);
    }

    protected function paged(Table $table, int $perPage = 25): Table
    {
        return $table
            ->records(function (?array $filters, ?string $search, int|string $page, int|string $recordsPerPage) {
                $rows = $this->cachedRows($filters, $search);
                $per = $recordsPerPage === 'all' ? max(1, $rows->count()) : (int) $recordsPerPage;

                return new LengthAwarePaginator($rows->forPage((int) $page, $per), $rows->count(), $per, (int) $page);
            })
            ->paginated([25, 50, 100])->defaultPaginationPageOption($perPage)
            // Ticks are kept by key across pages; the bulk buttons get every ticked row, not just this page's.
            ->resolveSelectedRecordsUsing(function (array $keys, array $deselectedKeys, bool $isTrackingDeselectedKeys) {
                $rows = $this->cachedRows($this->tableFilters ?? null, $this->getTableSearch());

                return $isTrackingDeselectedKeys ? $rows->except($deselectedKeys) : $rows->only($keys);
            });
    }
}
