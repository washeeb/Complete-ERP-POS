<?php

use App\Domain\Inventory\InventoryService;
use App\Filament\Pages\Insights;
use App\Models\Warehouse;
use App\Security\ScopeType;

// Insights in the new back office (release 7, part 2): the current page's tabs as report tables, the same access
// (company-wide figures need a company-wide grant), the warehouse choice and the export.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->wh = Warehouse::where('branch_id', $this->b['HQ']->id)->first();
    $milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh, $milo, $milo->units->first(), '20', 'opening', '', null, '40.00');
    $this->fm = withMfa(staff($this->owner, 'kwame', 5, 'finance_manager'));
});

it('shows every tab as tables to a company-wide manager, and exports the tab shown', function () {
    $this->actingAs($this->fm, 'web');
    $d = $this->getJson('/office-api/insights')->assertOk()->json();
    expect($d['tab'])->toBe('forecast')->and(array_column($d['tabs'], 'id'))->toBe(array_keys(Insights::TABS))
        ->and($d['by_warehouse'])->toBeTrue()->and($d['warehouse'])->not->toBe('')
        ->and($d['tables'][0]['title'])->toBe('Demand forecast and reorder')->and($d['tables'][0]['empty'])->toContain('No sales');

    $stock = $this->getJson("/office-api/insights?tab=stock&warehouse={$this->wh->id}")->assertOk()->json();
    expect($stock['warehouse'])->toBe($this->wh->id)->and(array_column($stock['tables'], 'title'))
        ->toBe(['May expire unsold', 'Dead stock', 'Overstock', 'Transfers between branches']);
    $dead = collect($stock['tables'][1]['rows'])->first(fn ($r) => $r['cells'][0] === 'MILO');
    expect($dead)->not->toBeNull()->and($dead['cells'][4])->toBe('Never here');

    foreach (['customers', 'pricing', 'cash', 'loss'] as $tab) {
        $x = $this->getJson("/office-api/insights?tab={$tab}")->assertOk()->json();
        expect($x['tab'])->toBe($tab)->and($x['tables'])->not->toBeEmpty();
    }
    expect($this->getJson('/office-api/insights?tab=cash')->json('by_warehouse'))->toBeFalse();

    $csv = $this->get("/office-api/insights/export?tab=stock&warehouse={$this->wh->id}&format=csv");
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('Dead stock')->toContain('MILO')->toContain('Never here');
});

it('keeps company-wide figures from a branch manager, and Insights from a teller', function () {
    $bm = withMfa(staff($this->owner, 'abena', 4, 'branch_manager', ScopeType::Branch, $this->b['HQ']->id));
    $this->actingAs($bm, 'web');
    $d = $this->getJson('/office-api/insights?tab=cash')->assertOk()->json();
    expect(array_column($d['tabs'], 'id'))->toBe(['forecast', 'stock', 'loss'])->and($d['tab'])->toBe('forecast'); // cash is not offered

    $teller = staff($this->owner, 'efua', 2, 'teller', ScopeType::Branch, $this->b['HQ']->id);
    $this->actingAs($teller, 'web')->getJson('/office-api/insights')->assertForbidden();
    $this->get('/office-api/insights/export?format=csv')->assertForbidden();
});
