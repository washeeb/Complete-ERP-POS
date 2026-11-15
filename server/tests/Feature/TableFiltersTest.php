<?php

use App\Domain\Customers\CustomerService;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\Customer;
use Livewire\Livewire;

// Filament hands a filter (and modifyQueryUsing) the table's query only by the parameter name $query. Named anything else
// ($q), it receives an empty query: the filter did nothing, or the list crashed (found 2026-11-15: Customers → Owes money
// broke the customer list; the audit log's Computer and Sign-ins filters did nothing).

it('names the query $query in every table filter and query tweak, so it is the table\'s', function () {
    $bad = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament'))) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        foreach (file($file->getPathname()) as $i => $line) {
            if (preg_match('/(->query|modifyQueryUsing)\(\s*(static\s+)?(fn|function)\s*\(\s*(\??[\w\x5c]+\s+)?\$(\w+)/', $line, $m) && $m[5] !== 'query') {
                $bad[] = str_replace(app_path(), 'app', $file->getPathname()).':'.($i + 1)." \${$m[5]}";
            }
        }
    }
    expect($bad)->toBe([]);
});

it('filters the customer list to those who owe money', function () {
    [, $owner] = installCompany();
    setUpBranches($owner);
    $svc = app(CustomerService::class);
    $owes = $svc->save(actorFor($owner), new Customer, ['name' => 'Owes Ltd', 'phone' => '0244000001']);
    $svc->openingBalance(actorFor($owner), $owes, 5000, now()->toDateString());
    $clear = $svc->save(actorFor($owner), new Customer, ['name' => 'Clear Ltd', 'phone' => '0244000002']);

    $this->actingAs(withMfa($owner), 'web');
    Livewire::test(ListCustomers::class)->filterTable('owing')->assertCanSeeTableRecords([$owes])->assertCanNotSeeTableRecords([$clear]);
});
