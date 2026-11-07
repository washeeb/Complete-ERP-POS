<?php

use App\Domain\Imports\ImportService;
use App\Filament\Pages\ImportData;
use App\Models\DataImport;
use App\Models\Industry;
use App\Models\Product;
use App\Support\Progress;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    setUpBranches($this->owner, [['code' => 'SM', 'name' => 'Supermarket', 'is_hq' => true, 'tills' => 1, 'modules' => ['grocery']]]);
    $this->grocery = Industry::where('module_key', 'grocery')->firstOrFail();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner), 'web');
    ImportService::$backgroundFrom = 2; // a "large" file here is three rows
    $this->csv = fn () => UploadedFile::fake()->createWithContent('products.csv', "sku,name,price_retail\n,Rice 5kg,90\n,Oil 1L,40\n,Sugar 1kg,25\n");
});

it('runs a large file in the background, shows it waiting, and tells the person when it is done', function () {
    $page = Livewire::test(ImportData::class, ['type' => 'products', 'industry' => $this->grocery->id])->set('file', ($this->csv)())->call('import')
        ->assertHasNoErrors()->assertSee('Waiting to start');
    $import = DataImport::sole();
    expect($import->status)->toBe('queued')->and($import->rows_total)->toBe(3)->and(Product::count())->toBe(0);

    // The same file cannot be sent twice while it waits.
    $page->set('file', ($this->csv)())->call('import')->assertNotified();
    expect(DataImport::count())->toBe(1);

    Artisan::call('erp:imports');
    expect($import->fresh()->status)->toBe('imported')->and($import->fresh()->rows_ok)->toBe(3)
        ->and(Product::whereIn('name', ['RICE 5KG', 'OIL 1L', 'SUGAR 1KG'])->pluck('industry_id')->unique()->all())->toBe([$this->grocery->id])
        ->and($this->owner->notifications()->latest()->first()->data['title'])->toBe('Imported products.csv: 3 row(s)');
    $page->call('$refresh')->assertSee('Imported')->assertDontSee('Waiting to start');
});

it('checks a large file in the background without saving anything', function () {
    Livewire::test(ImportData::class, ['type' => 'products', 'industry' => $this->grocery->id])->set('file', ($this->csv)())->call('check');
    Artisan::call('erp:imports');
    fwrite(STDERR, json_encode(DataImport::sole()->errors));
    expect(DataImport::sole()->status)->toBe('checked')->and(Product::count())->toBe(0);
});

it('marks a background import that stopped without finishing', function () {
    $stuck = DataImport::create(['tenant_id' => $this->tenant->id, 'type' => 'products', 'file_name' => 'x.csv', 'status' => 'running', 'rows_total' => 9,
        'rows_ok' => 0, 'rows_error' => 0, 'user_id' => $this->owner->id, 'user_name' => $this->owner->name]);
    DataImport::whereKey($stuck->id)->update(['updated_at' => now()->subHours(4)]);
    Artisan::call('erp:imports');
    expect($stuck->fresh()->status)->toBe('failed')->and($stuck->fresh()->errors[0]['message'])->toContain('stopped before finishing');
});

it('shows the time left in seconds only under a minute', function () {
    expect(Progress::timeLeft(45))->toBe('About 45 s left')
        ->and(Progress::timeLeft(200))->toBe('About 3 min 20 s left')
        ->and(Progress::timeLeft(120))->toBe('About 2 min left');
});

it('imports a checked file from the copy on the server, without uploading it again', function () {
    $page = Livewire::test(ImportData::class, ['type' => 'products', 'industry' => $this->grocery->id])->set('file', ($this->csv)())->call('check');
    Artisan::call('erp:imports');
    $checked = DataImport::sole();
    expect($checked->status)->toBe('checked')
        ->and($this->owner->notifications()->latest()->first()->data['actions'][0]['url'])->toContain('show='.$checked->id);

    // The page that started it says it is done, and offers the import.
    $page->call('followBackground')->assertNotified('Checked: all 3 row(s) are fine — press “Import this file” to import it')->assertSee('Import this file');

    // The bell's link opens the same result; importing uses the stored file (large: in the background again).
    Livewire::withQueryParams(['type' => 'products', 'show' => $checked->id])->test(ImportData::class)->assertSee('Import this file')
        ->call('importChecked', $checked->id)->assertSee('Waiting to start');
    Artisan::call('erp:imports');
    expect(DataImport::where('status', 'imported')->sole()->rows_ok)->toBe(3)
        ->and(Product::whereIn('name', ['RICE 5KG', 'OIL 1L', 'SUGAR 1KG'])->count())->toBe(3);

    // Not twice.
    Livewire::test(ImportData::class, ['type' => 'products'])->call('importChecked', $checked->id)->assertNotified();
    expect(DataImport::where('status', 'imported')->count())->toBe(1);
});
