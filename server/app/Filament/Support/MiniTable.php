<?php

namespace App\Filament\Support;

use Illuminate\Support\HtmlString;

/** A small read-only table for record pages (lines of a document), styled to work in light and dark themes. */
final class MiniTable
{
    /** @param list<string> $headings @param list<list<string|int|float|null>> $rows @param list<int> $numeric column indexes aligned right */
    public static function html(array $headings, array $rows, array $numeric = [], string $empty = 'Nothing yet.'): HtmlString
    {
        if (! $rows) {
            return new HtmlString('<p style="opacity:.6;font-size:.85rem">'.e($empty).'</p>');
        }
        $align = fn (int $i) => in_array($i, $numeric, true) ? 'text-align:right;' : 'text-align:left;';
        $line = 'border-top:1px solid color-mix(in srgb, currentColor 8%, transparent)';
        $head = collect($headings)->map(fn ($h, $i) => '<th style="'.$align($i).'padding:4px 6px">'.e($h).'</th>')->implode('');
        $body = collect($rows)->map(fn ($row) => '<tr>'.collect(array_values($row))->map(fn ($v, $i) => '<td style="'.$align($i).'padding:4px 6px;'.$line.'">'.e((string) $v).'</td>')->implode('').'</tr>')->implode('');

        return new HtmlString('<div style="overflow-x:auto"><table style="width:100%;font-size:.85rem;border-collapse:collapse"><tr>'.$head.'</tr>'.$body.'</table></div>');
    }
}
