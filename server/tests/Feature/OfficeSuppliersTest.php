<?php

use App\Models\Supplier;
use App\Models\SupplierDocument;
use App\Security\Reconfirm;
use App\Security\ScopeType;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// Suppliers in the new back office (release 5, part 1): the list, one supplier, the form with the products they supply,
// and the current screen's actions through SupplierService, SupplierOnboarding, SupplierPortal and SupplierPaymentService.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->owner = withMfa($this->owner);
    $milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00']);
    $this->ctn = $milo->units->firstWhere('is_base', false);
    $this->buyer = staff($this->owner, 'ama', 2, 'purchasing_officer');
    $this->gm = staff($this->owner, 'kojo', 5, 'general_manager');
    $this->values = fn (array $v = []) => ['values' => $v + ['name' => 'ernest chemists', 'contact_name' => 'Mr Mensah', 'phone' => '0302 123 456', 'email' => 'orders@ernest.test',
        'tax_no' => '', 'payment_terms_days' => '30', 'lead_time_days' => '3', 'return_days_before_expiry' => '90', 'address' => '', 'notes' => '', 'supply_kind' => 'goods',
        'bank_name' => 'GCB', 'bank_branch' => 'Osu', 'bank_account_name' => 'Ernest Chemists Ltd', 'bank_account_no' => '1234567890', 'momo_number' => '',
        'products' => [['product_uom_id' => $this->ctn->id, 'supplier_sku' => 'EC-MILO', 'last_cost' => '240', 'min_order_qty' => '2', 'order_multiple' => '1', 'is_preferred' => true]]]];
});

it('adds a supplier with the products they supply, finds them, edits them and exports the list', function () {
    $this->actingAs(withMfa($this->buyer), 'web');
    expect($this->getJson('/office-api/suppliers/units?q=milo')->assertOk()->json('results.0.id'))->not->toBeNull();
    $id = $this->postJson('/office-api/suppliers', ($this->values)())->assertOk()->json('id');
    $s = Supplier::with('products')->find($id);
    expect($s->name)->toBe('ERNEST CHEMISTS')->and($s->payment_terms_days)->toBe(30)->and($s->return_days_before_expiry)->toBe(90)
        ->and($s->products)->toHaveCount(1)->and($s->products[0]->last_cost_minor)->toBe(24000)->and($s->bank_verified_at)->toBeNull();

    $list = $this->getJson('/office-api/suppliers?q=mensah')->assertOk()->json();
    expect($list['total'])->toBe(1)->and($list['rows'][0])->toMatchArray(['code' => $s->code, 'products' => 1, 'terms' => '30 days', 'bank_unchecked' => true]);
    expect($this->getJson('/office-api/suppliers?unverified=1')->json('total'))->toBe(1);

    $form = $this->getJson("/office-api/suppliers/{$id}/form")->assertOk()->json();
    expect($form['values']['products'][0])->toMatchArray(['supplier_sku' => 'EC-MILO', 'last_cost' => '240.00', 'is_preferred' => true]);
    $this->postJson("/office-api/suppliers/{$id}", ['values' => ['products' => [], 'lead_time_days' => ''] + $form['values']])->assertOk();
    expect($s->fresh()->products()->count())->toBe(0)->and($s->fresh()->lead_time_days)->toBeNull();
    $this->postJson('/office-api/suppliers', ($this->values)(['products' => [['product_uom_id' => '']]]))->assertStatus(422);

    $csv = $this->get('/office-api/export/suppliers?format=csv');
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('ERNEST CHEMISTS');
});

it('asks to confirm it is you before new bank details, and a second person verifies them', function () {
    $this->actingAs(withMfa($this->buyer), 'web');
    Reconfirm::$assumeConfirmed = false;
    $this->postJson('/office-api/suppliers', ($this->values)())->assertStatus(428)->assertJsonPath('error', 'reconfirm')->assertJsonStructure(['url']);
    expect(Supplier::count())->toBe(0);
    Reconfirm::$assumeConfirmed = true;
    $id = $this->postJson('/office-api/suppliers', ($this->values)())->assertOk()->json('id');

    $this->postJson("/office-api/suppliers/{$id}/action", ['action' => 'verify_bank', 'how' => 'Called Mr Mensah on our old number'])->assertForbidden(); // a buyer does not approve
    $this->actingAs(withMfa($this->gm), 'web');
    expect($this->getJson("/office-api/suppliers/{$id}")->json('actions.verify_bank.confirm'))->toContain('1234567890');
    $after = $this->postJson("/office-api/suppliers/{$id}/action", ['action' => 'verify_bank', 'how' => 'Called Mr Mensah on our old number'])->assertOk()->json('supplier');
    expect($after['bank']['checked'])->toStartWith('By Kojo')->and($after['actions']['verify_bank'])->toBeNull();
});

it('with supplier controls on: documents, approval by someone else, block and unblock', function () {
    Storage::fake('local');
    app(Settings::class)->set(actorFor($this->owner), 'purchasing.supplier_approval', true);
    $this->actingAs(withMfa($this->buyer), 'web');
    $id = $this->postJson('/office-api/suppliers', ($this->values)(['tax_no' => 'C0012345678']))->assertOk()->json('id');
    $show = $this->getJson("/office-api/suppliers/{$id}")->json();
    expect($show['status'])->toBe('pending')->and($show['gaps'])->not->toBeEmpty()->and($show['actions']['approve'])->toBeFalse();

    $this->post("/office-api/suppliers/{$id}/documents", ['kind' => 'tin_certificate', 'number' => 'C0012345678',
        'file' => UploadedFile::fake()->create('tin.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertOk();
    $after = $this->postJson("/office-api/suppliers/{$id}/documents", ['kind' => 'business_registration', 'number' => 'CS123',
        'expires_on' => now()->addMonths(6)->toDateString()])->assertOk()->json('supplier');
    expect($after['gaps'])->toBe([])->and($after['documents']['rows'])->toHaveCount(2);
    $tin = SupplierDocument::where('kind', 'tin_certificate')->first();
    $this->get("/office-api/suppliers/{$id}/documents/{$tin->id}/file")->assertOk();
    $this->postJson("/office-api/suppliers/{$id}/documents", ['kind' => 'other_kind'])->assertStatus(422);

    $this->actingAs(withMfa($this->gm), 'web');
    $this->postJson("/office-api/suppliers/{$id}/documents/{$tin->id}", ['action' => 'verify'])->assertOk();
    expect($tin->fresh()->verified_by_name)->toBe('Kojo');
    expect($this->postJson("/office-api/suppliers/{$id}/action", ['action' => 'approve'])->assertOk()->json('supplier.status'))->toBe('approved');
    $this->postJson("/office-api/suppliers/{$id}/action", ['action' => 'block'])->assertStatus(422)->assertJsonValidationErrors('reason');
    expect($this->postJson("/office-api/suppliers/{$id}/action", ['action' => 'block', 'reason' => 'Licence suspended'])->json('supplier.blocked_reason'))->toBe('Licence suspended')
        ->and($this->getJson('/office-api/suppliers?status=blocked')->json('blocked'))->toBe(1);
    expect($this->postJson("/office-api/suppliers/{$id}/action", ['action' => 'unblock', 'reason' => 'Licence back'])->json('supplier.status'))->toBe('approved');

    $this->actingAs(withMfa($this->buyer), 'web');
    $this->postJson("/office-api/suppliers/{$id}/documents/{$tin->id}", ['action' => 'remove'])->assertStatus(422);
    $this->postJson("/office-api/suppliers/{$id}/documents/{$tin->id}", ['action' => 'remove', 'reason' => 'Wrong file'])->assertOk();
    expect(SupplierDocument::find($tin->id))->toBeNull();
});

it('sends the portal link, records the opening balance, archives and restores', function () {
    app(Settings::class)->set(actorFor($this->owner), 'purchasing.supplier_portal', true);
    $this->actingAs(withMfa($this->owner), 'web');
    $id = $this->postJson('/office-api/suppliers', ($this->values)(['bank_name' => '', 'bank_branch' => '', 'bank_account_name' => '', 'bank_account_no' => '']))->assertOk()->json('id');

    $r = $this->postJson("/office-api/suppliers/{$id}/action", ['action' => 'portal', 'channel' => 'none'])->assertOk()->json();
    expect($r['link'])->toContain('/s/')->and($r['supplier']['actions']['portal']['has_link'])->toBeTrue();

    $o = $this->postJson("/office-api/suppliers/{$id}/action", ['action' => 'opening', 'amount' => '1500', 'as_of' => now()->toDateString(), 'reference' => 'OLD-1'])->assertOk()->json('supplier');
    expect($o['owed'])->toBe(150000)->and($o['actions']['opening'])->toBeFalse()
        ->and($this->getJson('/office-api/suppliers?owed=1')->json('owed'))->toBe(150000);

    expect($this->postJson("/office-api/suppliers/{$id}/action", ['action' => 'archive', 'reason' => 'no longer used'])->assertStatus(422)->json('detail'))->not->toBeEmpty(); // still owed
    $other = $this->postJson('/office-api/suppliers', ($this->values)(['name' => 'Other Pharma', 'phone' => '', 'bank_name' => '', 'bank_branch' => '', 'bank_account_name' => '', 'bank_account_no' => '']))->json('id');
    expect($this->postJson("/office-api/suppliers/{$other}/action", ['action' => 'archive', 'reason' => 'no longer used'])->assertOk()->json('supplier.archived'))->toBeTrue()
        ->and($this->getJson('/office-api/suppliers?show=archived')->json('total'))->toBe(1);
    expect($this->postJson("/office-api/suppliers/{$other}/action", ['action' => 'restore'])->assertOk()->json('supplier.archived'))->toBeFalse();
});

it('lets a teller see nothing', function () {
    $teller = staff($this->owner, 'efua', 3, 'cashier', ScopeType::Branch, $this->b['HQ']->id);
    $this->actingAs(withMfa($teller), 'web')->getJson('/office-api/suppliers')->assertForbidden();
});
