<?php

namespace App\Filament\Support;

use App\Support\Exports\Exporter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * "Export" on every list in the back office (added to all tables in AppServiceProvider): what is on screen — the columns
 * shown, with the current search, filters and sort, all pages — as Excel, CSV or PDF. Values are the ones displayed (money with
 * its currency, dates in local time), so the file matches the screen.
 */
final class TableExport
{
    public static function group(): ActionGroup
    {
        return ActionGroup::make([
            Action::make('exportXlsx')->label('Export to Excel (.xlsx)')->icon(Heroicon::OutlinedTableCells)
                ->action(fn (HasTable $livewire) => self::download($livewire, 'xlsx')),
            Action::make('exportCsv')->label('Export to CSV')->icon(Heroicon::OutlinedDocumentText)
                ->action(fn (HasTable $livewire) => self::download($livewire, 'csv')),
            Action::make('exportPdf')->label('Export to PDF')->icon(Heroicon::OutlinedDocumentArrowDown)
                ->action(fn (HasTable $livewire) => self::download($livewire, 'pdf')),
        ])->label('Export')->icon(Heroicon::OutlinedArrowDownTray)->button()->color('gray')
            ->visible(fn () => Erp::user() !== null);
    }

    public static function download(HasTable $livewire, string $format)
    {
        $table = $livewire->getTable();
        $columns = array_values(array_filter($table->getVisibleColumns(), fn (Column $c) => trim(self::plain($c->getLabel())) !== ''));
        $title = self::title($livewire);
        $query = $livewire->getFilteredSortedTableQuery();
        $rows = (function () use ($query, $columns) {
            foreach ($query->lazy(500) as $record) {
                yield array_map(fn (Column $c) => self::value($c, $record), $columns);
            }
        })();
        $sheet = ['title' => $title, 'headings' => array_map(fn (Column $c) => self::plain($c->getLabel()), $columns), 'rows' => $rows];
        $filters = self::filters($livewire);

        return Erp::run(fn ($actor) => match ($format) {
            'pdf' => app(Exporter::class)->pdf($actor, $title, [$sheet], $filters, count($columns) > 5 ? 'landscape' : 'portrait'),
            'xlsx' => app(Exporter::class)->xlsx($actor, $title, [$sheet], $filters),
            default => app(Exporter::class)->csv($actor, $title, [$sheet], $filters),
        });
    }

    /** The text a cell shows. */
    public static function value(Column $column, $record): string
    {
        $column->record($record);
        $state = $column->getState();
        if ($column instanceof IconColumn && (is_bool($state) || $state === null)) {
            return $state ? 'Yes' : 'No';
        }
        $states = $state instanceof Collection ? $state->all() : Arr::wrap($state);
        $parts = array_map(fn ($s) => self::plain($column instanceof TextColumn ? $column->formatState($s) : $s), $states);

        return implode(', ', array_filter($parts, fn ($p) => $p !== ''));
    }

    public static function plain(mixed $v): string
    {
        $v = match (true) {
            $v instanceof HasLabel => $v->getLabel(),
            $v instanceof BackedEnum => $v->value,
            $v instanceof Htmlable => $v->toHtml(),
            $v instanceof \DateTimeInterface => $v->format('Y-m-d H:i'),
            is_bool($v) => $v ? 'Yes' : 'No',
            is_array($v) => implode(', ', array_map([self::class, 'plain'], $v)),
            is_object($v) && ! method_exists($v, '__toString') => '',
            default => (string) $v,
        };

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $v), ENT_QUOTES | ENT_HTML5)));
    }

    private static function title(HasTable $livewire): string
    {
        $heading = $livewire->getTable()->getHeading();
        if (filled($heading)) {
            return self::plain($heading);
        }
        if (method_exists($livewire, 'getTitle')) {
            try {
                return self::plain($livewire->getTitle());
            } catch (\Throwable) {
            }
        }

        return Str::headline(class_basename($livewire));
    }

    /** "Status: Active", "Search: rice" … for the PDF header and the audit log. */
    private static function filters(HasTable $livewire): array
    {
        $out = [];
        try {
            foreach ($livewire->getTable()->getFilterIndicators() as $indicator) {
                $out[] = self::plain($indicator->getLabel());
            }
        } catch (\Throwable) {
        }
        $search = method_exists($livewire, 'getTableSearch') ? $livewire->getTableSearch() : null;
        if (filled($search)) {
            $out[] = 'Search: '.$search;
        }

        return array_values(array_filter($out));
    }
}
