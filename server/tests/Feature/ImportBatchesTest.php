<?php

use App\Domain\Imports\ImportService;
use App\Models\DataImport;
use App\Models\Product;

// 120 rows = three batches; row 110 (in the last batch) has an unknown unit.
beforeEach(function () {
    [, $this->owner] = installCompany();
    $csv = "sku,name,base_unit,price_retail\n";
    for ($i = 1; $i <= 120; $i++) {
        $csv .= 'B'.$i.',Batch product '.$i.','.($i === 110 ? 'NOPE' : 'PC').','.(5 + $i)."\n";
    }
    $this->path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
    file_put_contents($this->path, $csv);
    $this->run = fn (bool $commit, bool $skip = false) => app(ImportService::class)->run(actorFor($this->owner), 'products', $this->path, 'products.csv', ['skip_errors' => $skip], null, $commit);
});

it('checks a long file batch by batch and keeps nothing', function () {
    $import = ($this->run)(false);
    expect($import->status)->toBe('checked')->and($import->rows_error)->toBe(1)->and($import->errors[0]['row'])->toBe(111)
        ->and(Product::where('sku', 'like', 'B%')->count())->toBe(0);
});

it('imports nothing when a row in a later batch has an error', function () {
    $import = ($this->run)(true);
    expect($import->status)->toBe('failed')->and(Product::where('sku', 'like', 'B%')->count())->toBe(0);
});

it('imports the good rows of every batch when told to skip errors', function () {
    $import = ($this->run)(true, true);
    expect($import->status)->toBe('imported')->and($import->rows_ok)->toBe(119)->and(Product::where('sku', 'like', 'B%')->count())->toBe(119);
});

it('imports a clean long file completely, and counts each row once', function () {
    file_put_contents($this->path, str_replace(',NOPE,', ',PC,', file_get_contents($this->path)));
    $import = ($this->run)(true);
    expect($import->status)->toBe('imported')->and($import->summary['created'])->toBe(120)
        ->and(Product::where('sku', 'like', 'B%')->count())->toBe(120)->and(DataImport::count())->toBe(1);
});
