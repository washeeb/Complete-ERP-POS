<?php

use App\Models\BankAccount;
use App\Models\BankStatementLine;
use App\Models\DayClose;
use App\Models\DrugInteraction;
use App\Models\ExpenseClaim;
use App\Models\FixedAsset;
use App\Models\HealthReading;
use App\Models\Product;
use App\Models\SavedReport;

// erp:demo-records: a sample of each kind of record a demo company has none of, through the services; run again, it adds nothing.

it('adds a sample of each missing kind through the services, and nothing twice', function () {
    [, $owner] = installCompany();
    setUpBranches($owner, [
        ['code' => 'HQ', 'name' => 'Head Office', 'is_hq' => true, 'tills' => 1, 'modules' => ['retail_wholesale']],
        ['code' => 'PH', 'name' => 'Pharmacy', 'is_hq' => false, 'tills' => 1, 'modules' => ['pharmacy']],
    ]);

    $this->artisan('erp:demo-records', ['--as' => $owner->email])->assertSuccessful();
    expect(BankAccount::where('kind', 'bank')->count())->toBe(1)->and(BankStatementLine::count())->toBe(2)
        ->and(ExpenseClaim::pluck('description')->sort()->values()->all())->toBe(['Fuel for the delivery van (sample)', 'Till pay-out: Cleaning materials (sample)'])
        ->and(FixedAsset::count())->toBe(1)->and(SavedReport::count())->toBe(1)->and(DrugInteraction::count())->toBeGreaterThan(0)->and(DayClose::count())->toBe(1)
        ->and(Product::whereNotNull('reading_kind')->pluck('reading_kind')->sort()->values()->all())->toBe(['hb', 'hiv', 'typhoid', 'wound'])
        ->and(HealthReading::count())->toBe(5);
    expect(HealthReading::where('kind', 'hiv')->sole()->result)->toBe('non_reactive');

    // A part that cannot be made (no supplier, no stock, no sale in a new company) is reported, and the rest still run.
    $this->artisan('erp:demo-records', ['--as' => $owner->email])->expectsOutputToContain('already there')->assertSuccessful();
    expect(BankAccount::where('kind', 'bank')->count())->toBe(1)->and(HealthReading::count())->toBe(5)->and(ExpenseClaim::count())->toBe(2);
});

it('refuses to run on a live system', function () {
    [, $owner] = installCompany();
    app()->detectEnvironment(fn () => 'production');
    $this->artisan('erp:demo-records', ['--as' => $owner->email])->assertFailed();
    expect(BankAccount::count())->toBe(0);
});
