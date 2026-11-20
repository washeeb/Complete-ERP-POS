<?php

use App\Domain\Assets\AssetService;
use App\Models\Account;
use App\Models\AssetClass;
use App\Models\FixedAsset;
use App\Security\ScopeType;
use Illuminate\Support\Carbon;

// Fixed assets in the new back office (release 8, part 4): the register, one asset with its depreciation, register,
// dispose of, and the kinds of asset, through AssetService.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->accountant = withMfa(staff($this->owner, 'esi', 4, 'accountant'));
    $this->comp = AssetClass::where('code', 'COMP')->sole();
});

it('registers an asset, shows its depreciation, finds it, and disposes of it', function () {
    $this->actingAs($this->accountant, 'web');
    $o = $this->getJson('/office-api/fixed-assets/options')->assertOk()->json();
    expect($o['can_manage'])->toBeTrue()->and(collect($o['kinds'])->firstWhere('id', $this->comp->id)['life_months'])->toBe(36);

    $this->postJson('/office-api/fixed-assets', ['name' => 'Laptop', 'asset_class_id' => $this->comp->id, 'branch_id' => $this->b['HQ']->id, 'acquired_on' => now()->toDateString(),
        'cost' => '1000', 'residual' => '2000'])->assertStatus(422); // residual above cost
    $new = $this->postJson('/office-api/fixed-assets', ['name' => 'Dell laptop (finance)', 'asset_class_id' => $this->comp->id, 'branch_id' => $this->b['HQ']->id,
        'acquired_on' => now()->subMonths(3)->startOfMonth()->toDateString(), 'cost' => '3600', 'serial_no' => 'SN-1'])->assertOk()->json('asset');
    expect($new['open'])->toBe("/office/fixed-assets/{$new['id']}");
    app(AssetService::class)->calculate($this->tenant->id, Carbon::now());

    $a = $this->getJson("/office-api/fixed-assets/{$new['id']}")->assertOk()->json('asset');
    expect($a)->toMatchArray(['name' => 'Dell laptop (finance)', 'cost' => 360000, 'life_months' => 36, 'status' => 'active', 'can_dispose' => true])
        ->and($a['schedule'])->not->toBeEmpty()->and($a['book_now'])->toBe(360000 - array_sum(array_column($a['schedule'], 'amount')));
    $l = $this->getJson('/office-api/fixed-assets?q=SN-1&status=active')->assertOk()->json();
    expect($l)->toMatchArray(['total' => 1, 'cost' => 360000])->and($l['rows'][0]['book'])->toBe($a['book_now']);
    $csv = $this->get('/office-api/export/fixed-assets?format=csv');
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('Book value')->toContain('Dell laptop (finance)');

    $this->postJson("/office-api/fixed-assets/{$new['id']}/dispose", ['date' => now()->toDateString(), 'reason' => 'Sold to staff', 'proceeds' => '500'])->assertStatus(422); // where the money went
    $this->postJson("/office-api/fixed-assets/{$new['id']}/dispose", ['date' => now()->toDateString(), 'reason' => 'Sold to staff', 'proceeds' => '500',
        'account_id' => Account::where('system_key', 'bank')->value('id')])->assertOk();
    $gone = $this->getJson("/office-api/fixed-assets/{$new['id']}")->json('asset');
    expect($gone)->toMatchArray(['status' => 'disposed', 'book' => 0, 'can_dispose' => false])->and($gone['disposal']['reason'])->toBe('Sold to staff');
    expect($this->getJson('/office-api/fixed-assets?status=active')->json('total'))->toBe(0);
});

it('lists, adds and changes kinds of asset, and keeps assets from a teller', function () {
    $this->actingAs($this->accountant, 'web');
    $k = $this->getJson('/office-api/asset-kinds')->assertOk()->json();
    expect($k['can_manage'])->toBeTrue()->and(collect($k['rows'])->firstWhere('code', 'COMP')['cost_account'])->not->toBeNull();
    $comp = collect($k['rows'])->firstWhere('code', 'COMP');
    $accounts = ['cost_account_id' => $comp['cost_account_id'], 'accumulated_account_id' => $comp['accumulated_account_id'], 'expense_account_id' => $comp['expense_account_id']];

    $this->postJson('/office-api/asset-kinds', ['name' => 'Generators', 'life_months' => 0] + $accounts)->assertStatus(422);
    $id = $this->postJson('/office-api/asset-kinds', ['name' => 'Generators', 'life_months' => 60] + $accounts)->assertOk()->json('class.id');
    $this->postJson("/office-api/asset-kinds/{$id}", ['name' => 'Generators and inverters', 'life_months' => 72] + $accounts)->assertOk();
    expect(AssetClass::find($id))->name->toBe('Generators and inverters')->life_months->toBe(72);

    $teller = staff($this->owner, 'efua', 2, 'teller', ScopeType::Branch, $this->b['HQ']->id);
    $this->actingAs($teller, 'web')->getJson('/office-api/fixed-assets')->assertForbidden();
    $this->getJson('/office-api/asset-kinds')->assertForbidden();
    expect(FixedAsset::count())->toBe(0);
});
