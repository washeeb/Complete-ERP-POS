<?php

use App\Domain\Customers\CustomerService;
use App\Domain\Imports\CsvFile;
use App\Domain\Imports\ImportService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockAdjustmentService;
use App\Domain\Inventory\StockCountService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\FinancialReports;
use App\Filament\Pages\ImportData;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\StockAdjustments\Pages\ViewStockAdjustment;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DataImport;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockLevel;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Exports\Exporter;
use App\Support\Settings;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = $this->branches['HQ'];
    $this->wh = Warehouse::where('branch_id', $this->hq->id)->first();
    $this->o = actorFor($this->owner);
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $this->imports = app(ImportService::class);
});

/** A CSV file on disk from rows (first row = headings). */
function importCsvFile(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
    $f = fopen($path, 'w');
    foreach ($rows as $r) {
        fputcsv($f, $r, escape: '');
    }
    fclose($f);

    return $path;
}

function skuOnHand(Warehouse $wh, string $sku): string
{
    return (string) (StockLevel::where('warehouse_id', $wh->id)->where('product_id', Product::where('sku', $sku)->value('id'))->value('on_hand') ?? '0.000');
}

it('exports any back-office list and report page to CSV and PDF, and neutralises spreadsheet formulas', function () {
    makeProduct($this->owner, 'RICE-5', 'Rice 5 kg', retail: '120.00');
    makeProduct($this->owner, 'OIL-1', 'Cooking oil 1 L', retail: '45.00');
    $this->actingAs(withMfa($this->owner));

    $c = Livewire::test(ListProducts::class)->set('tableSearch', 'rice')->callAction(TestAction::make('exportCsv')->table())->assertFileDownloaded();
    $csv = base64_decode($c->effects['download']['content']);
    expect($csv)->toContain('RICE-5')->not->toContain('OIL-1')->and(str_starts_with($csv, "\u{FEFF}"))->toBeTrue();
    $p = Livewire::test(ListProducts::class)->callAction(TestAction::make('exportPdf')->table())->assertFileDownloaded();
    expect(substr(base64_decode($p->effects['download']['content']), 0, 4))->toBe('%PDF');
    $tb = Livewire::test(FinancialReports::class)->callAction('exportPageCsv')->assertFileDownloaded();
    expect(base64_decode($tb->effects['download']['content']))->toContain('Account,Opening,Debit,Credit,Closing'); // the report's own table
    Livewire::test(FinancialReports::class)->callAction('exportPagePdf')->assertFileDownloaded();

    expect(Exporter::cell('=HYPERLINK("x")'))->toBe("'=HYPERLINK(\"x\")")->and(Exporter::cell('-12.50'))->toBe('-12.50')->and(Exporter::cell(true))->toBe('Yes')
        ->and(AuditEvent::where('action', 'data.exported')->count())->toBeGreaterThanOrEqual(4);
});

it('reads CSV files the way spreadsheets save them', function () {
    $path = tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents($path, "\xEF\xBB\xBFSKU*;Unit Cost (GHS);Name\r\nA-1;1 250,00;Caf\xC3\xA9\r\n;;\r\n");
    $r = CsvFile::read($path);
    expect($r['headings'])->toBe(['sku', 'unit_cost_ghs', 'name'])->and($r['rows'])->toHaveCount(1)->and($r['rows'][0]['values']['name'])->toBe('Café');
    file_put_contents($path, "sku,name\nB-1,Caf\xE9\n"); // Windows-1252 from Excel
    expect(CsvFile::read($path)['rows'][0]['values']['name'])->toBe('Café');
});

it('checks a product file without saving, then creates and updates products with prices, all or nothing', function () {
    makeProduct($this->owner, 'RICE-5', 'Rice 5 kg', retail: '120.00');
    $file = importCsvFile([
        ['sku', 'name', 'category', 'tax_group', 'barcodes', 'price_RETAIL', 'for_sale'],
        ['RICE-5', 'Perfumed rice 5 kg', '', '', '', '118.50', ''],
        ['SUGAR-1', 'Sugar 1 kg', 'Groceries', 'STD', '6001234567893', '22.00', 'yes'],
        ['BAD SKU!', 'Broken', '', '', '', '', ''],
    ]);
    $check = $this->imports->run($this->o, 'products', $file, 'products.csv', [], null, false);
    expect($check->status)->toBe('checked')->and($check->rows_error)->toBe(1)->and($check->errors[0]['row'])->toBe(4)
        ->and(Product::where('sku', 'SUGAR-1')->exists())->toBeFalse()->and(Product::where('sku', 'RICE-5')->value('name'))->toBe('Rice 5 kg');

    // All or nothing: one bad row stops the whole file.
    expect($this->imports->run($this->o, 'products', $file, 'products.csv', [], null, true)->status)->toBe('failed')
        ->and(Product::where('sku', 'SUGAR-1')->exists())->toBeFalse();

    $done = $this->imports->run($this->o, 'products', $file, 'products.csv', ['skip_errors' => true, 'create_missing' => true], null, true);
    $rice = Product::where('sku', 'RICE-5')->first();
    $sugar = Product::where('sku', 'SUGAR-1')->first();
    expect($done->status)->toBe('imported')->and($done->summary['created'])->toBe(1)->and($done->summary['updated'])->toBe(1)
        ->and($rice->name)->toBe('Perfumed rice 5 kg')->and($sugar->category_id)->toBe(Category::where('name', 'Groceries')->value('id'))
        ->and($sugar->barcodes()->value('code'))->toBe('6001234567893')
        ->and(app(PriceResolver::class)->resolve($this->retail, $rice->id, $rice->units->first()->id, 1000, now())->amount_minor)->toBe(11850)
        ->and($rice->barcodes()->count())->toBe(1); // barcodes untouched when the column is empty

    // The current data comes out in the same layout, ready to edit and import again.
    $rows = iterator_to_array(ImportService::importer('products')->exportRows($this->o, [], null), false);
    expect(collect($rows)->firstWhere(0, 'SUGAR-1')[1])->toBe('Sugar 1 kg');

    $cashier = staff($this->owner, 'efua', 2, 'teller', ScopeType::Branch, $this->hq->id);
    expect(fn () => $this->imports->run(actorFor($cashier), 'products', $file, 'x.csv', [], null, true))->toThrow(AccessDenied::class)
        ->and(DataImport::count())->toBe(3);
});

it('imports prices, customers and suppliers', function () {
    makeProduct($this->owner, 'RICE-5', 'Rice 5 kg', retail: '120.00', wholesale: '110.00');
    $p = $this->imports->run($this->o, 'prices', importCsvFile([
        ['sku', 'price_list', 'price', 'unit', 'min_qty', 'starts_at', 'ends_at'],
        ['RICE-5', 'WHOLESALE', '105.00', '', '', '', ''],
        ['RICE-5', 'RETAIL', '115.00', 'PC', '10', '', ''],
        ['RICE-5', 'RETAIL', '120.00', '', '', '', ''],
    ]), 'prices.csv', [], null, true);
    $rice = Product::where('sku', 'RICE-5')->first();
    $unit = $rice->units->first()->id;
    expect($p->summary)->toMatchArray(['updated' => 1, 'added' => 1, 'unchanged' => 1])
        ->and(app(PriceResolver::class)->resolve(PriceList::where('code', 'WHOLESALE')->first(), $rice->id, $unit, 1000, now())->amount_minor)->toBe(10500)
        ->and(app(PriceResolver::class)->resolve($this->retail, $rice->id, $unit, 12000, now())->amount_minor)->toBe(11500); // bulk tier

    $existing = app(CustomerService::class)->save($this->o, new Customer, ['name' => 'Ama Owusu', 'phone' => '0241112222']);
    $c = $this->imports->run($this->o, 'customers', importCsvFile([
        ['code', 'name', 'phone', 'email', 'price_list', 'credit_limit', 'payment_terms_days'],
        ['', 'Ama Owusu-Mensah', '0241112222', 'ama@example.com', '', '', ''],
        ['', 'Kofi Provisions', '0245551234', '', 'WHOLESALE', '5000', '30'],
    ]), 'customers.csv', [], null, true);
    $kofi = Customer::where('phone', '+233245551234')->first();
    expect($c->summary)->toMatchArray(['created' => 1, 'updated' => 1])->and($existing->fresh()->name)->toBe('Ama Owusu-Mensah')
        ->and($kofi->credit_limit_minor)->toBe(500000)->and($kofi->payment_terms_days)->toBe(30);

    $s = $this->imports->run($this->o, 'suppliers', importCsvFile([['code', 'name', 'payment_terms_days', 'active'], ['OLAM', 'Olam Ghana', '30', 'yes']]), 's.csv', [], null, true);
    expect($s->summary['created'])->toBe(1)->and(Supplier::where('code', 'OLAM')->value('payment_terms_days'))->toBe(30);
});

it('adjusts stock with increase, decrease and set-to lines, needing approval above the limit', function () {
    makeProduct($this->owner, 'RICE-5', 'Rice 5 kg', retail: '120.00');
    makeProduct($this->owner, 'OIL-1', 'Oil 1 L', retail: '45.00');
    $this->imports->run($this->o, 'opening_stock', importCsvFile([['warehouse', 'sku', 'qty', 'unit_cost'], [$this->wh->code, 'RICE-5', '40', '95.00'], [$this->wh->code, 'OIL-1', '10', '30.00']]),
        'opening.csv', [], null, true);
    $rice = Product::where('sku', 'RICE-5')->first();
    expect(skuOnHand($this->wh, 'RICE-5'))->toBe('40.000')
        ->and(fn () => app(InventoryService::class)->adjust($this->o, $this->wh, $rice, $rice->units->first(), '5', 'opening', ''))->toThrow(DomainException::class, 'already has stock history');

    $svc = app(StockAdjustmentService::class);
    $a = $svc->create($this->o, $this->wh, ['reason_code' => 'damaged', 'reference' => 'DR-7']);
    $svc->addLine($this->o, $a, ['sku' => 'RICE-5', 'mode' => 'remove', 'qty' => '2']);
    $svc->addLine($this->o, $a, ['sku' => 'OIL-1', 'mode' => 'set', 'qty' => '7', 'reason_code' => 'theft']);
    $svc->addLine($this->o, $a, ['sku' => 'RICE-5', 'mode' => 'add', 'qty' => '1', 'unit_cost' => '95.00', 'reason_code' => 'found']);
    expect($a->number)->toStartWith('ADJ-')->and(fn () => $svc->addLine($this->o, $a, ['sku' => 'RICE-5', 'mode' => 'remove', 'qty' => '0']))->toThrow(DomainException::class);

    // GHS 95 × 2 + 30 × 3 + 95 = 375 — under the default GHS 500 limit: posted straight away.
    $svc->post($this->o, $a->fresh());
    expect(skuOnHand($this->wh, 'RICE-5'))->toBe('39.000')->and(skuOnHand($this->wh, 'OIL-1'))->toBe('7.000')->and($a->fresh()->status)->toBe('posted')
        ->and(fn () => $svc->post($this->o, $a->fresh()))->toThrow(DomainException::class, 'already');

    // Above the limit: submitted by one person, approved by another.
    app(Settings::class)->set($this->o, 'stock.adjustment_approval_minor', 1000);
    $big = $svc->create($this->o, $this->wh, ['reason_code' => 'expired']);
    $svc->addLine($this->o, $big, ['sku' => 'RICE-5', 'mode' => 'remove', 'qty' => '5']);
    expect(fn () => $svc->post($this->o, $big->fresh()))->toThrow(DomainException::class, 'submit it first');
    $svc->submit($this->o, $big->fresh());
    expect(fn () => $svc->post($this->o, $big->fresh()))->toThrow(DomainException::class, 'someone other');
    $gm = staff($this->owner, 'kwame', 5, 'general_manager', ScopeType::Tenant, $this->tenant->id);
    $svc->post(actorFor($gm), $big->fresh());
    expect(skuOnHand($this->wh, 'RICE-5'))->toBe('34.000');
});

it('updates stock from the stock sheet as a set-to adjustment, skipping unchanged rows', function () {
    makeProduct($this->owner, 'RICE-5', 'Rice 5 kg', retail: '120.00');
    makeProduct($this->owner, 'OIL-1', 'Oil 1 L', retail: '45.00');
    $inv = app(InventoryService::class);
    foreach (['RICE-5' => '40', 'OIL-1' => '10'] as $sku => $q) {
        $p = Product::where('sku', $sku)->first();
        $inv->adjust($this->o, $this->wh, $p, $p->units->first(), $q, 'opening', '', null, '10.00');
    }
    $importer = ImportService::importer('stock_adjustment');
    $sheet = iterator_to_array($importer->exportRows($this->o, ['warehouse_id' => $this->wh->id], null), false);
    $rows = [$importer->headings()];
    foreach ($sheet as $r) {
        if ($r[0] === 'OIL-1') {
            $r[2] = '8'; // counted 8 instead of 10
        }
        $rows[] = $r;
    }
    $done = $this->imports->run($this->o, 'stock_adjustment', importCsvFile($rows), 'sheet.csv', ['warehouse_id' => $this->wh->id, 'reason_code' => 'correction'], null, true);
    $adj = StockAdjustment::findOrFail($done->context_id);
    expect($done->summary)->toMatchArray(['added' => 1, 'unchanged' => 1])->and($adj->lines)->toHaveCount(1)->and($adj->status)->toBe('draft');
    app(StockAdjustmentService::class)->post($this->o, $adj);
    expect(skuOnHand($this->wh, 'OIL-1'))->toBe('8.000')->and(skuOnHand($this->wh, 'RICE-5'))->toBe('40.000');
});

it('imports counted quantities into a stock count from its count sheet', function () {
    makeProduct($this->owner, 'RICE-5', 'Rice 5 kg', retail: '120.00', baseBarcode: '6001067000017');
    $p = Product::where('sku', 'RICE-5')->first();
    app(InventoryService::class)->adjust($this->o, $this->wh, $p, $p->units->first(), '40', 'opening', '', null, '10.00');
    $count = app(StockCountService::class)->start($this->o, $this->wh, blind: false);
    $importer = ImportService::importer('stock_count');
    $sheet = iterator_to_array($importer->exportRows($this->o, [], $count), false);
    expect($sheet[0][0])->toBe('RICE-5')->and($sheet[0][6])->toBe('40'); // expected shown on a non-blind count

    $done = $this->imports->run($this->o, 'stock_count', importCsvFile([['sku', 'counted_qty', 'barcode'], ['', '37', '6001067000017']]), 'count.csv', [], $count, true);
    expect($done->summary['added'])->toBe(1)->and((string) $count->lines()->first()->counted_qty)->toBe('37.000');
});

it('shows the import and stock adjustment screens and exports an adjustment voucher', function () {
    makeProduct($this->owner, 'RICE-5', 'Rice 5 kg', retail: '120.00');
    $p = Product::where('sku', 'RICE-5')->first();
    app(InventoryService::class)->adjust($this->o, $this->wh, $p, $p->units->first(), '40', 'opening', '', null, '10.00');
    $a = app(StockAdjustmentService::class)->create($this->o, $this->wh, ['reason_code' => 'damaged']);
    app(StockAdjustmentService::class)->addLine($this->o, $a, ['sku' => 'RICE-5', 'mode' => 'remove', 'qty' => '3']);
    $this->actingAs(withMfa($this->owner));
    foreach ([ImportData::getUrl(['type' => 'products']), ImportData::getUrl(['type' => 'stock_adjustment']), StockAdjustmentResource::getUrl('index'),
        StockAdjustmentResource::getUrl('create'), StockAdjustmentResource::getUrl('view', ['record' => $a])] as $url) {
        $this->get($url)->assertOk();
    }
    $this->get(StockAdjustmentResource::getUrl('view', ['record' => $a]))->assertSee('Rice 5 kg')->assertSee('37.000');
    Livewire::test(ImportData::class, ['type' => 'products'])->call('downloadTemplate')->assertFileDownloaded();
    Livewire::test(ViewStockAdjustment::class, ['record' => $a->id])->callAction('voucherPdf')->assertFileDownloaded();
});
