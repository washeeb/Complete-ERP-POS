<?php

use App\Models\Customer;
use App\Models\PatientProfile;
use App\Security\ScopeType;
use Illuminate\Support\Facades\DB;

// Customers in the new back office (release 4, part 1): the list, one customer, the form and the current page's actions,
// through CustomerService, ReceivablesService, PharmacyService and ArchiveService.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner, [
        ['code' => 'HQ', 'name' => 'Head Office', 'is_hq' => true, 'tills' => 0, 'modules' => ['pharmacy']],
        ['code' => 'P1', 'name' => 'Pharmacy Osu', 'is_hq' => false, 'tills' => 0, 'modules' => ['pharmacy']],
    ]);
    $this->owner = withMfa($this->owner);
    $this->values = fn (array $v = []) => ['values' => $v + ['type' => 'business', 'name' => 'kofi provisions', 'phone' => '024 555 1234', 'email' => '',
        'contact_name' => 'Kofi', 'tax_no' => '', 'price_list_id' => '', 'home_branch_id' => $this->b['HQ']->id, 'address' => '', 'tags' => ['wholesale'],
        'notes' => '', 'consents' => ['sms' => true, 'whatsapp' => false, 'email' => false], 'consent_source' => 'paper form', 'credit_limit' => '500', 'payment_terms_days' => '30']];
});

it('adds a customer with credit, finds them by phone, edits them and exports the list', function () {
    $this->actingAs($this->owner, 'web');
    $form = $this->getJson('/office-api/customers/form')->assertOk()->json();
    expect($form['sections']['credit'])->toBeTrue()->and(collect($form['choices']['types'])->pluck('id'))->toContain('business');

    $id = $this->postJson('/office-api/customers', ($this->values)())->assertOk()->json('id');
    $c = Customer::find($id);
    expect($c->name)->toBe('KOFI PROVISIONS')->and($c->phone)->toBe('+233245551234')->and($c->credit_limit_minor)->toBe(50000)
        ->and($c->payment_terms_days)->toBe(30)->and($c->hasConsent('sms'))->toBeTrue()->and($c->consents['sms']['source'] ?? null)->toBe('paper form');
    $this->postJson('/office-api/customers', ($this->values)(['name' => 'Someone else']))->assertStatus(422)->assertJsonPath('error', 'duplicate_phone');

    $list = $this->getJson('/office-api/customers?q=0245551234')->assertOk()->json();
    expect($list['total'])->toBe(1)->and($list['rows'][0])->toMatchArray(['code' => $c->code, 'credit_limit' => 50000, 'archived' => false]);
    expect($this->getJson('/office-api/customers?credit=1')->json('credit'))->toBe(1);

    $edit = $this->getJson("/office-api/customers/{$id}/form")->assertOk()->json();
    expect($edit['values']['consents']['sms'])->toBeTrue()->and($edit['sections']['credit'])->toBeFalse();
    $this->postJson("/office-api/customers/{$id}", ['values' => ['email' => 'kofi@example.test', 'credit_limit' => ''] + $edit['values']])->assertOk();
    expect($c->fresh()->email)->toBe('kofi@example.test')->and($c->fresh()->credit_limit_minor)->toBe(50000); // credit is changed with "Credit" only

    expect($this->get('/office-api/export/customers?format=csv&q=kofi')->streamedContent())->toContain($c->code)->toContain('KOFI PROVISIONS');
});

it('sets credit, records an opening balance and a payment, and shows what is owed', function () {
    $this->actingAs($this->owner, 'web');
    $id = $this->postJson('/office-api/customers', ($this->values)(['credit_limit' => '']))->json('id');
    $doc = $this->getJson("/office-api/customers/{$id}")->assertOk()->json();
    expect($doc['actions']['opening'])->toBeTrue()->and($doc['actions']['pay'])->not->toBeNull()->and($doc['actions']['statement'])->toContain("/print/statement/{$id}");

    $this->postJson("/office-api/customers/{$id}/action", ['action' => 'credit', 'limit' => '1000', 'terms' => 14, 'hold' => false])->assertStatus(422); // a reason is needed
    $doc = $this->postJson("/office-api/customers/{$id}/action", ['action' => 'credit', 'limit' => '1000', 'terms' => 14, 'hold' => true, 'reason' => 'Agreed with the owner'])
        ->assertOk()->json();
    expect($doc['hold'])->toBeTrue()->and($doc['account']['limit'])->toBe(100000)->and($doc['account']['terms'])->toBe('14 days');

    $doc = $this->postJson("/office-api/customers/{$id}/action", ['action' => 'opening', 'amount' => '250', 'as_of' => now()->subMonths(2)->toDateString()])->assertOk()->json();
    expect($doc['account']['balance'])->toBe(25000)->and($doc['actions']['opening'])->toBeFalse()->and($doc['open_items'])->toHaveCount(1);

    $pay = ['action' => 'pay', 'branch_id' => $this->b['HQ']->id, 'amount' => '100', 'method' => 'momo', 'provider' => 'mtn', 'reference' => ''];
    $this->postJson("/office-api/customers/{$id}/action", $pay)->assertStatus(422); // MoMo needs its transaction number
    $doc = $this->postJson("/office-api/customers/{$id}/action", ['reference' => 'MP2611180001'] + $pay)->assertOk()->json();
    expect($doc['account']['balance'])->toBe(15000)->and($doc['open_items'][0]['open'])->toBe(15000);
    expect($this->getJson('/office-api/customers?owing=1')->json())->toMatchArray(['total' => 1, 'owing' => 1, 'owed' => 15000]);
    expect($this->getJson('/office-api/customers?sort=balance&dir=desc')->json('rows.0.balance'))->toBe(15000);
});

it('keeps patient details, archives with a reason and restores', function () {
    $this->actingAs($this->owner, 'web');
    $id = $this->postJson('/office-api/customers', ($this->values)(['credit_limit' => '', 'payment_terms_days' => '0']))->json('id');
    $pharmacist = withMfa(staff($this->owner, 'adwoa', 4, 'pharmacist', ScopeType::Branch, $this->b['P1']->id));

    $this->actingAs($pharmacist, 'web');
    $doc = $this->getJson("/office-api/customers/{$id}")->assertOk()->json();
    expect($doc['actions']['patient'])->not->toBeNull()->and($doc['actions']['credit'])->toBeNull()->and($doc['actions']['pay'])->toBeNull();
    $doc = $this->postJson("/office-api/customers/{$id}/action", ['action' => 'patient', 'sex' => 'male', 'allergies' => ['penicillin'], 'date_of_birth' => '1980-05-01'])
        ->assertOk()->json();
    expect($doc['patient']['Allergies'])->toBe('penicillin')->and(PatientProfile::find($id)->allergies)->toBe(['penicillin']);
    $this->postJson("/office-api/customers/{$id}/action", ['action' => 'credit', 'limit' => '1', 'terms' => 1, 'hold' => false, 'reason' => 'x'])->assertForbidden();

    $this->actingAs($this->owner, 'web');
    $this->postJson("/office-api/customers/{$id}/action", ['action' => 'archive'])->assertStatus(422);
    $doc = $this->postJson("/office-api/customers/{$id}/action", ['action' => 'archive', 'reason' => 'Moved away'])->assertOk()->json();
    expect($doc['archived'])->toBeTrue()->and($doc['archive_note'])->toContain('Moved away')->and($doc['actions']['restore'])->toBeTrue();
    expect($this->getJson('/office-api/customers')->json('total'))->toBe(0)->and($this->getJson('/office-api/customers?show=archived')->json('total'))->toBe(1);
    $this->postJson("/office-api/customers/{$id}/action", ['action' => 'restore'])->assertOk()->assertJsonPath('archived', false);
});

it('lets a teller look but not change', function () {
    $this->actingAs($this->owner, 'web');
    $id = $this->postJson('/office-api/customers', ($this->values)())->json('id');
    $teller = withMfa(staff($this->owner, 'esi', 2, 'teller', ScopeType::Branch, $this->b['HQ']->id));

    $this->actingAs($teller, 'web');
    expect($this->getJson('/office-api/customers')->assertOk()->json('total'))->toBe(1);
    expect($this->getJson('/office-api/customers/options')->json('can_create'))->toBeFalse();
    $doc = $this->getJson("/office-api/customers/{$id}")->assertOk()->json();
    expect($doc['actions'])->toMatchArray(['edit' => false, 'pay' => null, 'credit' => null, 'archive' => null]);
    $this->getJson('/office-api/customers/form')->assertForbidden();
    $this->postJson("/office-api/customers/{$id}", ($this->values)())->assertForbidden();
    $this->postJson("/office-api/customers/{$id}/action", ['action' => 'archive', 'reason' => 'Not mine'])->assertForbidden();
    expect(DB::table('customers')->whereNotNull('archived_at')->count())->toBe(0);
});
