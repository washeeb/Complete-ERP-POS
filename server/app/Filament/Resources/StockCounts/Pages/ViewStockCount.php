<?php

namespace App\Filament\Resources\StockCounts\Pages;

use App\Domain\Imports\ImportService;
use App\Filament\Pages\ImportData;
use App\Filament\Resources\StockCounts\StockCountResource;
use App\Filament\Support\Erp;
use App\Support\Exports\Exporter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewStockCount extends ViewRecord
{
    protected static string $resource = StockCountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...StockCountResource::countActions(),
            Action::make('importCounts')->label('Import counted quantities (CSV)')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')
                ->visible(fn () => $this->record->status === 'counting' && Erp::canAnywhere('stock.count'))
                ->url(fn () => ImportData::getUrl(['type' => 'stock_count', 'context' => $this->record->id])),
            ActionGroup::make([
                Action::make('sheetPdf')->label('Count sheet (PDF, to count on paper)')->icon(Heroicon::OutlinedPrinter)->action(fn () => $this->sheet('pdf')),
                Action::make('sheetCsv')->label('Count sheet (CSV, to fill in and import)')->icon(Heroicon::OutlinedTableCells)->action(fn () => $this->sheet('csv')),
            ])->label('Count sheet')->icon(Heroicon::OutlinedArrowDownTray)->button()->color('gray'),
        ];
    }

    /** The lines of this count as a sheet: SKU, barcode, product, unit, bin, counted (blank to fill) and, unless blind, the expected quantity. */
    private function sheet(string $format)
    {
        $i = ImportService::importer('stock_count');
        $rows = iterator_to_array($i->exportRows(Erp::actor(), [], $this->record), false);
        $sheet = ['title' => "Count sheet {$this->record->number}", 'headings' => $i->headings(), 'rows' => $rows,
            'note' => $this->record->warehouse->code.' · '.$this->record->warehouse->name.($this->record->blind ? ' · Blind count: expected quantities are not shown.' : '')
                .' · Counted by: ____________________  Checked by: ____________________'];

        return Erp::run(fn ($actor) => $format === 'pdf' ? app(Exporter::class)->pdf($actor, $sheet['title'], [$sheet])
            : app(Exporter::class)->csv($actor, $sheet['title'], [$sheet]));
    }
}
