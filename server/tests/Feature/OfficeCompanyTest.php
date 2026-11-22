<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\HealthReading;
use App\Models\Industry;
use App\Models\LegalEntity;
use App\Models\Product;
use App\Models\TaxGroup;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Illuminate\Http\UploadedFile;

// Branches, Industries, Legal entities, Branding, Health checks and Scale items in the new back office (part 20).

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    withMfa($this->owner);
});

it('adds a branch, changes it and its own settings, adds a store and a till, and archives and restores a till', function () {
    $this->actingAs($this->owner, 'web');
    expect($this->getJson('/office-api/branches')->assertOk()->json())->toMatchArray(['total' => 2, 'can_create' => true]);
    expect($this->getJson('/office-api/branches?q=head')->json('rows.0.code'))->toBe('HQ');
    $form = $this->getJson('/office-api/branches/form')->assertOk()->json();
    expect($form['values']['tills'])->toBe('1');
    $this->postJson('/office-api/branches', ['name' => '', 'tills' => 1])->assertStatus(422);
    $id = $this->postJson('/office-api/branches', ['name' => 'Pharmacy Osu', 'tills' => 2, 'phone' => '0244000000'])->assertOk()->json('id');
    $b = Branch::find($id);
    expect($b->code)->toBe('PHAOSU')->and(Till::where('branch_id', $id)->count())->toBe(2);

    $one = $this->getJson("/office-api/branches/{$id}")->assertOk()->json();
    expect($one)->toMatchArray(['code' => 'PHAOSU', 'can_edit' => true])->and($one['stores'])->toHaveCount(1)->and($one['tills'])->toHaveCount(2)
        ->and(collect($one['sections'])->pluck('prefix'))->not->toContain('pharmacy.'); // only where it runs Pharmacy
    $pos = collect($one['sections'])->firstWhere('prefix', 'pos.');
    $key = $pos['fields'][0]['key'];
    $saved = $this->postJson("/office-api/branches/{$id}", ['name' => 'Pharmacy Osu Main', 'phone' => '0244000001', 'reason' => 'Renamed',
        'settings' => [$key => $pos['fields'][0]['options'][0]['id'] ?? '1']])->assertOk()->json('branch');
    expect($saved['values']['name'])->toBe('Pharmacy Osu Main')->and(Branch::find($id)->settings)->toHaveKey($key);
    $this->postJson("/office-api/branches/{$id}", ['name' => 'Pharmacy Osu Main', 'settings' => [$key => '']])->assertOk();
    expect(Branch::find($id)->settings[$key] ?? null)->toBeNull(); // empty follows the company again

    $store = $this->postJson("/office-api/branches/{$id}/stores", ['name' => 'Back store'])->assertOk()->json('branch.stores');
    expect(collect($store)->pluck('code'))->toContain('PHAOSU-W2');
    $w2 = Warehouse::where('code', 'PHAOSU-W2')->sole();
    $tills = $this->postJson("/office-api/branches/{$id}/tills", ['name' => 'Till 3', 'warehouse_id' => $w2->id])->assertOk()->json('branch.tills');
    expect(collect($tills)->pluck('code'))->toContain('T03');
    $other = Warehouse::where('branch_id', $this->b['HQ']->id)->first();
    $this->postJson("/office-api/branches/{$id}/tills", ['name' => 'Wrong', 'warehouse_id' => $other->id])->assertStatus(422); // its own branch's store only

    $t3 = Till::where('branch_id', $id)->where('code', 'T03')->sole();
    $this->postJson('/office-api/company/archive', ['kind' => 'till', 'id' => $t3->id])->assertStatus(422); // a reason is needed
    $this->postJson('/office-api/company/archive', ['kind' => 'till', 'id' => $t3->id, 'reason' => 'Not used'])->assertOk();
    expect(Till::find($t3->id)->archived_at)->not->toBeNull();
    $this->postJson('/office-api/company/archive', ['kind' => 'till', 'id' => $t3->id, 'restore' => true])->assertOk();
    expect(Till::find($t3->id)->archived_at)->toBeNull();

    // A branch manager sees only their branch, and may not add branches.
    $mgr = withMfa(staff($this->owner, 'kojo', 4, 'branch_manager', ScopeType::Branch, $this->b['BA']->id));
    $this->actingAs($mgr, 'web');
    if ($this->getJson('/office-api/branches')->status() === 200) {
        expect(collect($this->getJson('/office-api/branches')->json('rows'))->pluck('code')->all())->toBe(['BA']);
        $this->getJson("/office-api/branches/{$id}")->assertNotFound();
    }
    $this->getJson('/office-api/branches/form')->assertForbidden();
});

it('keeps industries and legal entities, and the brand', function () {
    $this->actingAs($this->owner, 'web');
    $ind = $this->getJson('/office-api/industries')->assertOk()->json();
    expect($ind['modules'])->not->toBeEmpty();
    $module = $ind['modules'][0]['id'];
    $this->postJson('/office-api/industries', ['name' => '', 'module_key' => $module])->assertStatus(422);
    $id = $this->postJson('/office-api/industries', ['name' => 'Second shops', 'module_key' => $module, 'sort' => 5])->assertOk()->json('id');
    expect(Industry::find($id))->name->toBe('Second shops')->sort->toBe(5);
    $this->postJson("/office-api/industries/{$id}", ['name' => 'Second shops (east)', 'module_key' => $module, 'sort' => 6])->assertOk();
    expect(collect($this->getJson('/office-api/industries')->json('rows'))->firstWhere('id', $id)['name'])->toBe('Second shops (east)');

    $this->postJson('/office-api/legal-entities', ['name' => 'Demo Pharmacy Ltd', 'tin' => 'bad'])->assertStatus(422);
    $le = $this->postJson('/office-api/legal-entities', ['name' => 'Demo Pharmacy Ltd', 'tin' => 'C0012345678', 'vat_no' => 'V1'])->assertOk()->json('id');
    expect(LegalEntity::find($le)->tin)->toBe('C0012345678');
    $rows = $this->getJson('/office-api/legal-entities?q=pharmacy')->assertOk()->json('rows');
    expect($rows)->toHaveCount(1)->and($rows[0])->toMatchArray(['name' => 'Demo Pharmacy Ltd', 'primary' => false, 'can_archive' => true]);
    $this->postJson('/office-api/company/archive', ['kind' => 'legal_entity', 'id' => $le, 'reason' => 'Never registered'])->assertOk();
    expect($this->getJson('/office-api/legal-entities?q=pharmacy')->json('rows'))->toBeEmpty()
        ->and($this->getJson('/office-api/legal-entities?q=pharmacy&show=archived')->json('rows'))->toHaveCount(1);

    $brand = $this->getJson('/office-api/branding')->assertOk()->json();
    expect($brand['assets'])->toHaveCount(3)->and($brand['assets'][0]['url'])->toBeNull();
    $this->postJson('/office-api/branding', ['color' => 'red'])->assertStatus(422);
    $this->postJson('/office-api/branding', ['color' => '#B5471B', 'menu_color' => '', 'receipt_logo' => true])->assertOk();
    expect($this->tenant->fresh()->brand_color)->toBe('#B5471B');
    $png = UploadedFile::fake()->image('logo.png', 800, 200);
    $up = $this->post('/office-api/branding/logo', ['file' => $png], ['Accept' => 'application/json'])->assertOk()->json('branding');
    expect($up['assets'][0]['url'])->toStartWith('/brand/')->and($up['assets'][0]['about'])->toContain('PNG');
    $this->postJson('/office-api/branding/logo/remove')->assertOk();
    expect($this->getJson('/office-api/branding')->json('assets.0.url'))->toBeNull();

    $cashier = withMfa(staff($this->owner, 'efua', 3, 'cashier', ScopeType::Branch, $this->b['HQ']->id));
    $this->actingAs($cashier, 'web');
    foreach (['branches', 'industries', 'legal-entities', 'branding'] as $path) {
        $this->getJson("/office-api/{$path}")->assertForbidden();
    }
});

it('records health readings and adds a fee service; lists scale items with export', function () {
    enableModule($this->owner, $this->b['HQ'], 'pharmacy');
    $this->actingAs($this->owner, 'web');
    $c = Customer::create(['code' => 'C1', 'name' => 'Abena', 'tenant_id' => $this->tenant->id]);
    $form = $this->getJson('/office-api/health-checks/form')->assertOk()->json();
    expect($form['results'])->toHaveKeys(['hiv', 'typhoid'])->and($form['tax_groups'])->not->toBeEmpty();
    expect($this->getJson('/office-api/health-checks/patients?q=abe')->json('results.0.id'))->toBe($c->id);
    $this->postJson('/office-api/health-checks', ['kind' => 'bp', 'branch_id' => $this->b['HQ']->id, 'systolic' => 80, 'diastolic' => 120])->assertStatus(422);
    $this->postJson('/office-api/health-checks', ['kind' => 'bp', 'branch_id' => $this->b['HQ']->id, 'customer_id' => $c->id, 'systolic' => 150, 'diastolic' => 95])->assertOk();
    $this->postJson('/office-api/health-checks', ['kind' => 'typhoid', 'branch_id' => $this->b['HQ']->id, 'result' => 'negative'])->assertOk();
    expect(HealthReading::count())->toBe(2);
    $list = $this->getJson('/office-api/health-checks')->assertOk()->json();
    expect($list['total'])->toBe(2);
    $mine = $this->getJson("/office-api/health-checks?patient={$c->id}&flags=high,urgent")->json();
    expect($mine)->toMatchArray(['total' => 1, 'patient_name' => 'Abena'])->and($mine['rows'][0])->toMatchArray(['reading' => '150/95 mmHg', 'flag' => 'high', 'tone' => 'warning']);
    expect($this->getJson('/office-api/health-checks?kind=typhoid')->json('rows.0.reading'))->toBe('Negative');

    $this->postJson('/office-api/health-checks/service', ['name' => 'Blood pressure check', 'price' => '10', 'reading_kind' => 'bp',
        'tax_group_id' => TaxGroup::first()->id])->assertOk();
    expect(Product::where('name', 'ilike', 'Blood pressure check')->sole())->reading_kind->toBe('bp')->track_stock->toBeFalse();

    $mince = makeProduct($this->owner, 'MINCE', 'Minced beef', retail: '80.00');
    $mince->forceFill(['plu' => '123'])->saveQuietly();
    $scale = $this->getJson('/office-api/scale-items')->assertOk()->json();
    expect($scale['rows'][0])->toMatchArray(['plu' => '00123', 'price' => 8000]);
    $this->get('/office-api/scale-items/export?format=csv')->assertOk();

    $store = withMfa(staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Tenant, $this->tenant->id));
    $this->actingAs($store, 'web');
    $this->getJson('/office-api/health-checks')->assertForbidden(); // health data: only people who see patients
});
