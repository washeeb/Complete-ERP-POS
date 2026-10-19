<?php

use App\Domain\Imports\ImportService;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ImportData;
use App\Models\Customer;
use App\Models\DataImport;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

beforeEach(function () {
    [, $this->owner] = installCompany();
    setUpBranches($this->owner);
    $this->csv = "name,phone\nAma Owusu,0241112222\nKofi Mensah,0201234567\n";
    $this->path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
    file_put_contents($this->path, $this->csv);
});

it('refuses to import the same file a second time unless asked to', function () {
    $run = fn (array $o = []) => app(ImportService::class)->run(actorFor($this->owner), 'customers', $this->path, 'customers.csv', $o, null, true);
    expect($run()->status)->toBe('imported');
    expect(fn () => $run())->toThrow(DomainException::class, 'already imported');
    expect($run(['import_again' => true])->status)->toBe('imported')
        ->and(Customer::count())->toBe(2) // customers are matched by phone: no copies
        ->and(DataImport::where('status', 'imported')->whereNotNull('file_hash')->count())->toBe(2);
    // Only checking it is always allowed.
    expect(app(ImportService::class)->run(actorFor($this->owner), 'customers', $this->path, 'customers.csv', [], null, false)->status)->toBe('checked');
});

it('starts the import page clean after an import, and warns before the same file goes in again', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner));
    $page = Livewire::test(ImportData::class)->set('type', 'customers')
        ->set('file', UploadedFile::fake()->createWithContent('customers.csv', $this->csv))->call('import');
    expect($page->get('file'))->toBeNull()->and($page->get('preview'))->toBeNull()->and($page->get('fileKey'))->toBe(1);

    $page->set('file', UploadedFile::fake()->createWithContent('customers-again.csv', $this->csv))->call('import')->assertNotified('Already imported');
    expect(DataImport::where('status', 'imported')->count())->toBe(1)->and($page->get('duplicateNote'))->toContain('already imported');
    $page->set('importAgain', true)->call('import');
    expect(DataImport::where('status', 'imported')->count())->toBe(2);
});
