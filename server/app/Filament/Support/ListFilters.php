<?php

namespace App\Filament\Support;

use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filters the sales and payment lists share, so they work the same everywhere (and Export takes the filtered list):
 * a person (teller, cashier, who received it), an amount from / up to, dates from / to.
 */
final class ListFilters
{
    /**
     * People who appear on the list's records (e.g. tellers who sold), several at once — taken from the page's own
     * list, so a person limited to their branch only sees names from it.
     *
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    public static function person(string $resource, string $idColumn, string $nameColumn, string $label): SelectFilter
    {
        return SelectFilter::make($idColumn)->label($label)->multiple()->searchable()
            ->options(fn () => $resource::getEloquentQuery()->reorder()->whereNotNull($idColumn)->select([$idColumn, $nameColumn])->distinct()
                ->orderBy($nameColumn)->limit(500)->pluck($nameColumn, $idColumn)->all());
    }

    /** An amount (in minor units in the table) from / up to, typed in cedis; $signed allows below zero (e.g. cash short). */
    public static function amount(string $column, string $label, bool $signed = false): Filter
    {
        return Filter::make($column.'_range')->label($label)
            ->schema([Grid::make(2)->schema([
                TextInput::make('from')->label($label.' from')->numeric()->minValue($signed ? null : 0),
                TextInput::make('to')->label('up to')->numeric()->minValue($signed ? null : 0),
            ])])
            ->query(fn (Builder $query, array $data) => $query
                ->when(($data['from'] ?? '') !== '' && $data['from'] !== null, fn ($w) => $w->where($column, '>=', FinanceUi::toMinor($data['from'])))
                ->when(($data['to'] ?? '') !== '' && $data['to'] !== null, fn ($w) => $w->where($column, '<=', FinanceUi::toMinor($data['to']))))
            ->indicateUsing(fn (array $data) => array_values(array_filter([
                ($data['from'] ?? '') !== '' && $data['from'] !== null ? $label.' from '.FinanceUi::money(FinanceUi::toMinor($data['from'])) : null,
                ($data['to'] ?? '') !== '' && $data['to'] !== null ? $label.' up to '.FinanceUi::money(FinanceUi::toMinor($data['to'])) : null,
            ])));
    }

    /** A date or time column between two days (the company's days). */
    public static function dates(string $column, string $label, bool $isDate = false): Filter
    {
        $tz = fn () => Erp::user()?->tenant?->timezone ?: 'UTC';

        return Filter::make($column.'_dates')->label($label)
            ->schema([Grid::make(2)->schema([DatePicker::make('from')->label($label.' from'), DatePicker::make('until')->label('until')])])
            ->query(fn (Builder $query, array $data) => $query
                ->when($data['from'] ?? null, fn ($w, $d) => $w->where($column, '>=', $isDate ? $d : CarbonImmutable::parse($d, $tz())->startOfDay()->utc()))
                ->when($data['until'] ?? null, fn ($w, $d) => $w->where($column, '<=', $isDate ? $d : CarbonImmutable::parse($d, $tz())->endOfDay()->utc())))
            ->indicateUsing(fn (array $data) => array_values(array_filter([
                ($data['from'] ?? null) ? $label.' from '.CarbonImmutable::parse($data['from'])->format('j M Y') : null,
                ($data['until'] ?? null) ? $label.' until '.CarbonImmutable::parse($data['until'])->format('j M Y') : null,
            ])));
    }
}
