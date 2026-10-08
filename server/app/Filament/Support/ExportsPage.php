<?php

namespace App\Filament\Support;

use App\Support\Exports\Exporter;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;

/**
 * "Export" on report pages (trial balance, aged debts, shrink, commissions, calendars…): every table the page shows, as
 * it is shown now (same dates, branch and filters), becomes a sheet of the CSV or PDF. Reading the rendered page keeps
 * the export identical to the screen without a second copy of each report's logic.
 */
trait ExportsPage
{
    protected function getHeaderActions(): array
    {
        return [$this->exportGroup()];
    }

    protected function exportGroup(): ActionGroup
    {
        return ActionGroup::make([
            Action::make('exportPageCsv')->label('Export to CSV (Excel)')->icon(Heroicon::OutlinedTableCells)->action(fn () => $this->exportPage('csv')),
            Action::make('exportPagePdf')->label('Export to PDF')->icon(Heroicon::OutlinedDocumentArrowDown)->action(fn () => $this->exportPage('pdf')),
        ])->label('Export')->icon(Heroicon::OutlinedArrowDownTray)->button()->color('gray');
    }

    /** Filters shown in the PDF header and the audit log; pages may add their own (dates, branch…). */
    protected function exportFilters(): array
    {
        $out = [];
        foreach (['from' => 'From', 'to' => 'To', 'date' => 'Date', 'season' => 'Season', 'report' => 'Report'] as $prop => $label) {
            if (property_exists($this, $prop) && filled($this->{$prop})) {
                $out[] = "{$label}: {$this->{$prop}}";
            }
        }

        return $out;
    }

    public function exportPage(string $format)
    {
        $sheets = self::tablesFromHtml($this->render()->render(), strip_tags((string) $this->getTitle()));
        $title = TableExport::plain($this->getTitle());

        return Erp::run(fn ($actor) => $format === 'pdf'
            ? app(Exporter::class)->pdf($actor, $title, $sheets, $this->exportFilters())
            : app(Exporter::class)->csv($actor, $title, $sheets, $this->exportFilters()));
    }

    /** @return list<array{title: string, headings: list<string>, rows: list<list<string>>}> */
    public static function tablesFromHtml(string $html, string $fallbackTitle): array
    {
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);
        // Buttons, links that act as buttons, scripts and styles are not data.
        foreach (iterator_to_array($xp->query('//button | //script | //style | //*[contains(@class, "fi-btn")] | //*[contains(@class, "fi-ac")]')) as $n) {
            $n->parentNode?->removeChild($n);
        }
        $sheets = [];
        foreach ($xp->query('//main//table | //table[not(ancestor::main)]') as $i => $table) {
            /** @var DOMElement $table */
            if ($xp->query('ancestor::table', $table)->length) {
                continue; // nested tables are part of their parent's cells
            }
            $headings = [];
            foreach ($xp->query('.//thead//th', $table) as $th) {
                $headings[] = self::text($th, $xp);
            }
            $rows = [];
            foreach ($xp->query('./tbody/tr | ./tr | ./tfoot/tr', $table) as $tr) {
                $cells = [];
                foreach ($xp->query('./th | ./td', $tr) as $cell) {
                    $cells[] = self::text($cell, $xp);
                    for ($k = 1; $k < (int) ($cell->getAttribute('colspan') ?: 1); $k++) {
                        $cells[] = '';
                    }
                }
                if (array_filter($cells, fn ($c) => $c !== '')) {
                    $rows[] = $cells;
                }
            }
            if (! $headings && $rows) {
                $headings = array_shift($rows);
            }
            if (! $headings && ! $rows) {
                continue;
            }
            $heading = $xp->query('preceding::*[self::h2 or self::h3][1]', $table)->item(0);
            $sheets[] = ['title' => $heading ? self::text($heading, $xp) : $fallbackTitle.($i ? ' ('.($i + 1).')' : ''), 'headings' => $headings, 'rows' => $rows];
        }

        return $sheets ?: [['title' => $fallbackTitle, 'headings' => ['Nothing to export'], 'rows' => []]];
    }

    private static function text(DOMElement $el, DOMXPath $xp): string
    {
        $parts = [];
        foreach ($xp->query('.//input[not(@type="hidden")] | .//select', $el) as $input) {
            $parts[] = $input->nodeName === 'select'
                ? trim((string) $xp->query('.//option[@selected]', $input)->item(0)?->textContent)
                : (string) $input->getAttribute('value');
        }

        return trim(preg_replace('/\s+/u', ' ', $el->textContent.' '.implode(' ', $parts)));
    }
}
