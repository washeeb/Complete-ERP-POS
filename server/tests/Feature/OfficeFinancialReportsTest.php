<?php

use App\Domain\Finance\AutoPoster;
use App\Domain\Inventory\InventoryService;
use App\Filament\Pages\FinancialReports;
use App\Models\Account;
use App\Models\Warehouse;
use App\Security\ScopeType;

// Financial reports in the new back office (release 7, part 1): each report as the current page has it, the same
// filters, and the export.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $wh = Warehouse::where('branch_id', $this->b['HQ']->id)->first();
    $milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    app(InventoryService::class)->adjust(actorFor($this->owner), $wh, $milo, $milo->units->first(), '20', 'opening', '', null, '40.00'); // GHS 800.00 of stock
    app(AutoPoster::class)->run($this->tenant->id);
    $this->fm = withMfa(staff($this->owner, 'kwame', 5, 'finance_manager'));
});

it('shows every report, with the trial balance opening the account ledger', function () {
    $this->actingAs($this->fm, 'web');
    $o = $this->getJson('/office-api/financial-reports/options')->assertOk()->json();
    expect(array_column($o['reports'], 'id'))->toBe(array_keys(FinancialReports::REPORTS));

    $tb = $this->getJson('/office-api/financial-reports?report=tb')->assertOk()->json();
    expect($tb)->toMatchArray(['report' => 'tb', 'title' => 'Trial balance', 'from' => now()->startOfMonth()->toDateString()]);
    $stock = collect($tb['tables'][0]['rows'])->first(fn ($r) => str_contains($r['cells'][0]['t'], 'nventory') || str_contains($r['cells'][0]['t'], 'tock'));
    expect($stock)->not->toBeNull()->and($stock['cells'][2])->toContain('800.00')->and($stock['cells'][0]['to'])->toContain('report=ledger');
    expect($tb['tables'][0]['columns'][1])->toBe(['label' => 'Opening', 'end' => true]);

    parse_str(ltrim($stock['cells'][0]['to'], '?'), $q);
    $ledger = $this->getJson('/office-api/financial-reports?'.http_build_query($q))->assertOk()->json();
    expect($ledger['tables'][0]['rows'][1]['cells'][1]['to'])->toStartWith('/office/journals/')
        ->and(end($ledger['tables'][0]['rows'])['cells'][6])->toContain('800.00');
    expect($this->getJson('/office-api/financial-reports?report=ledger')->json('prompt'))->toBe('Choose an account.');

    foreach (array_keys(FinancialReports::REPORTS) as $r) {
        $this->getJson("/office-api/financial-reports?report={$r}")->assertOk()->assertJsonPath('report', $r);
    }
    expect($this->getJson('/office-api/financial-reports?report=bs')->json('notes.0'))->toBe('Assets equal liabilities plus equity.');
    expect($this->getJson('/office-api/financial-reports?report=check')->json('notes.0'))->toContain('ties to its sub-ledgers');
    expect($this->getJson('/office-api/financial-reports?report=vat')->json('tables.1.title'))->toBe('Tax');
});

it('exports the report shown, and keeps it from people without finance access', function () {
    $this->actingAs($this->fm, 'web');
    $csv = $this->get('/office-api/financial-reports/export?report=tb&format=csv');
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('Opening')->toContain('800.00')->toContain('Total (debits + / credits −)');
    $this->get('/office-api/financial-reports/export?report=bs&format=xlsx')->assertOk();
    $this->get('/office-api/financial-reports/export?report=tb&format=doc')->assertStatus(422);
    $this->postJson('/office-api/financial-reports/share-head-office', ['to' => now()->toDateString()])->assertStatus(422); // sharing is off

    $teller = staff($this->owner, 'efua', 2, 'teller', ScopeType::Branch, $this->b['HQ']->id);
    $this->actingAs($teller, 'web')->getJson('/office-api/financial-reports')->assertForbidden();
    $this->get('/office-api/financial-reports/export?report=tb&format=csv')->assertForbidden();
    expect(Account::count())->toBeGreaterThan(0);
});
