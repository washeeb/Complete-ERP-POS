<?php

namespace App\Filament\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * The top search bar for a resource: what a result opens (its page, else its list filtered to it) and the details shown
 * under its title (SKU, number, customer, phone…). Resources list their searchable columns and details themselves.
 */
trait GlobalSearch
{
    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        foreach (['view' => 'canView', 'edit' => 'canEdit'] as $page => $can) {
            if (static::hasPage($page) && static::{$can}($record)) {
                return static::getUrl($page, ['record' => $record]);
            }
        }

        return static::getUrl('index', ['tableSearch' => (string) $record->getAttribute(static::getRecordTitleAttribute())]);
    }

    /** @param array<string, mixed> $details label => value; empty values are left out */
    protected static function searchDetails(array $details): array
    {
        return array_filter(array_map(fn ($v) => $v === null ? null : (string) $v, $details), fn ($v) => $v !== null && $v !== '');
    }
}
