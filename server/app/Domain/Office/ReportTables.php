<?php

namespace App\Domain\Office;

/**
 * Report tables for the new back office (financial reports, Insights): columns, rows of ready-written cells (text, or
 * {t, to, tone, strong}) and a total row. The page draws them as they come and the export writes the same cells, so
 * the screen and the file cannot disagree.
 */
final class ReportTables
{
    /**
     * @param  list<string>  $cols  column names; "#" at the start = a number column (right-aligned)
     * @param  list<array{kind?: string, cells: list<mixed>}>  $rows
     */
    public static function table(string $title, array $cols, array $rows, array $foot = [], ?string $hint = null, ?string $empty = null): array
    {
        return ['title' => $title, 'columns' => array_map(fn ($c) => ['label' => ltrim($c, '#'), 'end' => str_starts_with($c, '#')], $cols), 'rows' => $rows, 'foot' => $foot,
            'hint' => $hint, 'empty' => $empty];
    }

    /** Plain rows: each a list of cells. */
    public static function rows(iterable $items, callable $cells): array
    {
        $out = [];
        foreach ($items as $i) {
            $out[] = ['cells' => array_values($cells($i))];
        }

        return $out;
    }

    /** @return list<array{title: string, headings: list<string>, rows: list<list<string>>}> the tables as Exporter sheets */
    public static function sheets(array $tables, string $fallback = ''): array
    {
        $plain = fn ($c) => is_array($c) ? (string) ($c['t'] ?? '') : (string) ($c ?? '');
        $sheets = array_map(fn ($t) => ['title' => $t['title'], 'headings' => array_column($t['columns'], 'label'),
            'rows' => array_merge(array_map(fn ($r) => array_map($plain, $r['cells']), $t['rows']), $t['foot'] ? [array_map($plain, $t['foot'])] : [])], $tables);

        return $sheets ?: [['title' => $fallback, 'headings' => [''], 'rows' => [[$fallback]]]];
    }
}
