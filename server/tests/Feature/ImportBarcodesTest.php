<?php

use App\Domain\Imports\CsvFile;
use App\Domain\Imports\Importer;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ImportData;
use App\Models\Barcode;
use App\Support\Exports\Exporter;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

it('repairs barcodes Excel changed, and refuses ones it shortened', function () {
    expect(Importer::barcode('="0012345678905"'))->toBe('0012345678905')
        ->and(Importer::barcode("'0123"))->toBe('0123')
        ->and(Importer::barcode('6001067000123.0'))->toBe('6001067000123')
        ->and(Importer::barcode('6.001067000123E+12'))->toBe('6001067000123')
        ->and(Importer::barcode('12345678905'))->toBe('012345678905')   // UPC-A lost its zero
        ->and(Importer::barcode('1234565'))->toBe('01234565')           // EAN-8 lost its zero
        ->and(Importer::barcode('12345'))->toBe('12345')                // not a standard code: as typed
        ->and(Importer::barcode('ABC-001'))->toBe('ABC-001');
    expect(fn () => Importer::barcode('6.00107E+12'))->toThrow(DomainException::class, 'shortened by Excel');
    expect(Exporter::cell('0012345678905'))->toBe('="0012345678905"')
        ->and(Exporter::cell('6001067000123'))->toBe('="6001067000123"')
        ->and(Exporter::cell('25'))->toBe('25')
        ->and(CsvFile::unformula('="0123"'))->toBe('0123');
});

it('imports product barcodes with their leading zeros, and offers the files as Excel', function () {
    [, $owner] = installCompany();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($owner), 'web');
    $csv = "sku,name,barcodes,price_retail\n"
        .",Zero lead,=\"0012345678905\",25\n"
        .",Lost zero,12345678905,5\n"
        .",Shortened,6.00107E+12,15\n";
    Livewire::test(ImportData::class, ['type' => 'products'])
        ->set('file', UploadedFile::fake()->createWithContent('products.csv', $csv))
        ->set('skipErrors', true)->call('import');
    expect(Barcode::pluck('code')->sort()->values()->all())->toBe(['0012345678905', '012345678905']);

    Livewire::test(ImportData::class, ['type' => 'products'])->call('downloadTemplate', 'xlsx')->assertFileDownloaded();
    Livewire::test(ImportData::class, ['type' => 'products'])->call('downloadCurrent', 'xlsx')->assertFileDownloaded();
});
