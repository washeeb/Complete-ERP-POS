<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Imports\ImportService;
use App\Filament\Pages\ImportData;
use App\Models\Industry;
use App\Models\Product;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    setUpBranches($this->owner, [
        ['code' => 'PH', 'name' => 'Pharmacy', 'is_hq' => true, 'tills' => 1, 'modules' => ['pharmacy']],
        ['code' => 'RT', 'name' => 'Retail', 'is_hq' => false, 'tills' => 1, 'modules' => ['grocery']],
    ]);
    $this->pharmacy = Industry::where('module_key', 'pharmacy')->firstOrFail();
    $this->grocery = Industry::where('module_key', 'grocery')->firstOrFail();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner), 'web');
    $this->csv = fn (string $rows) => UploadedFile::fake()->createWithContent('products.csv', "sku,name,industry,price_retail\n".$rows);
});

it('asks which industry the products are for, and puts them there', function () {
    $this->get(ImportData::getUrl(['type' => 'products']))->assertOk()->assertSee('For which industry?')->assertSee('Shared by all industries');
    $page = Livewire::test(ImportData::class, ['type' => 'products'])->set('file', ($this->csv)(",Paracetamol,,5\n,Vitamin C,,8\n"))
        ->call('import')->assertHasErrors('industry');
    expect(Product::whereIn('name', ['Paracetamol', 'Vitamin C'])->count())->toBe(0); // nothing until the industry is chosen

    $page->set('industry', $this->pharmacy->id)->call('import')->assertHasNoErrors();
    expect(Product::whereIn('name', ['Paracetamol', 'Vitamin C'])->pluck('industry_id')->unique()->all())->toBe([$this->pharmacy->id]);
});

it('takes the industry from the page by default, or from an industry column per row', function () {
    Livewire::test(ImportData::class, ['type' => 'products', 'industry' => 'shared'])
        ->set('file', ($this->csv)(",Carrier bag,,1\n,Rice 5kg,grocery,90\n,Ibuprofen,Pharmacy,6\n"))->call('import')->assertHasNoErrors();
    expect(Product::where('name', 'Carrier bag')->value('industry_id'))->toBeNull()
        ->and(Product::where('name', 'Rice 5kg')->value('industry_id'))->toBe($this->grocery->id)
        ->and(Product::where('name', 'Ibuprofen')->value('industry_id'))->toBe($this->pharmacy->id);
});

it('downloads the chosen industry\'s products only, with their industry', function () {
    Livewire::test(ImportData::class, ['type' => 'products', 'industry' => $this->pharmacy->id])
        ->set('file', ($this->csv)(",Paracetamol,,5\n,Rice 5kg,grocery,90\n"))->call('import');
    $rows = iterator_to_array(app(ImportService::class)->importer('products')->exportRows(actorFor($this->owner), ['industry' => $this->pharmacy->id], null), false);
    expect(array_column($rows, 1))->toBe(['Paracetamol'])->and($rows[0][12])->toBe($this->pharmacy->name);
});

it('moves products imported to the wrong industry; their system SKUs take its series', function () {
    Livewire::test(ImportData::class, ['type' => 'products', 'industry' => 'shared'])->set('file', ($this->csv)(",Paracetamol,,5\n,Vitamin C,,8\n"))->call('import');
    $products = Product::whereIn('name', ['Paracetamol', 'Vitamin C'])->get();
    $skus = $products->pluck('sku')->all();
    expect(app(CatalogService::class)->moveToIndustry(actorFor($this->owner), $products, $this->pharmacy->id))->toBe(2)
        ->and(Product::whereIn('name', ['Paracetamol', 'Vitamin C'])->pluck('industry_id')->unique()->all())->toBe([$this->pharmacy->id])
        ->and($skus)->each->toMatch('/^1\d{5}$/') // shared: plain numbers
        ->and(Product::whereIn('name', ['Paracetamol', 'Vitamin C'])->pluck('sku')->all())->each->toStartWith($this->pharmacy->code.'-');
});
