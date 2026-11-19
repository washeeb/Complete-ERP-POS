<?php

use App\Domain\Payables\BillService;
use App\Domain\Purchasing\SupplierService;
use App\Models\Account;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Security\Reconfirm;
use App\Security\ScopeType;
use App\Support\Settings;

// Supplier payments and Aged payables in the new back office (release 6, part 3): the list, what is owed by how late,
// and Pay a supplier with the withholding tax worked out, through SupplierPaymentService.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->owner = withMfa($this->owner);
    $this->landlord = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => 'Osu Properties', 'supply_kind' => 'services']);
    $this->accountant = staff($this->owner, 'esi', 4, 'accountant');
    $this->fm = staff($this->owner, 'kwame', 5, 'finance_manager');
    app(BillService::class)->create(actorFor($this->accountant), $this->landlord, $this->b['HQ'], ['supplier_invoice_no' => 'R-OCT', 'bill_date' => now()->subDays(40)->toDateString(),
        'due_on' => now()->subDays(10)->toDateString(), 'lines' => [['account_id' => Account::where('code', '6200')->value('id'), 'description' => 'October rent', 'amount_minor' => 300000]]]);
    $this->bank = Account::where('system_key', 'bank')->first();
    $this->pay = fn (array $v = []) => $v + ['supplier_id' => $this->landlord->id, 'account_id' => $this->bank->id, 'method' => 'bank', 'amount' => '1000', 'withhold' => false,
        'reference' => 'TRF-1', 'paid_on' => now()->toDateString(), 'branch_id' => $this->b['HQ']->id];
});

it('shows what is owed by how late, pays part of it and lists the payment', function () {
    $this->actingAs($this->fm, 'web');
    $aging = $this->getJson('/office-api/aged-payables')->assertOk()->json();
    expect($aging['rows'][0])->toMatchArray(['name' => 'OSU PROPERTIES', 'd1_30' => 300000, 'total' => 300000])->and($aging['pay'])->not->toBeNull();

    $p = $this->postJson('/office-api/supplier-payments', ($this->pay)())->assertOk()->json('payment');
    expect($p)->toMatchArray(['amount' => 100000, 'wht' => 0, 'paid' => 100000, 'reference' => 'TRF-1']);
    expect($this->getJson('/office-api/aged-payables')->json('rows.0.total'))->toBe(200000);
    $list = $this->getJson('/office-api/supplier-payments?q=TRF-1&method=bank')->assertOk()->json();
    expect($list['total'])->toBe(1)->and($list['settled'])->toBe(100000);
    $csv = $this->get('/office-api/export/supplier-payments?format=csv');
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('OSU PROPERTIES');
    $this->postJson('/office-api/supplier-payments', ($this->pay)(['amount' => '0']))->assertStatus(422);
});

it('works out and keeps back withholding tax, with its certificate', function () {
    app(Settings::class)->set(actorFor($this->owner), 'payables.wht', true);
    $this->actingAs($this->fm, 'web');
    expect($this->getJson('/office-api/supplier-payments/options')->json('pay.wht'))->toBeTrue();
    expect($this->getJson("/office-api/supplier-payments/preview?supplier={$this->landlord->id}&amount=10&withhold=1&wht=")->json('text'))->toContain('Below the withholding tax threshold');
    $preview = $this->getJson("/office-api/supplier-payments/preview?supplier={$this->landlord->id}&amount=3000&withhold=1&wht=")->assertOk()->json('text');
    expect($preview)->toContain('before VAT')->toContain('Pay the supplier');
    $p = $this->postJson('/office-api/supplier-payments', ($this->pay)(['withhold' => true, 'amount' => '3000']))->assertOk()->json('payment');
    expect($p['wht'])->toBeGreaterThan(0)->and($p['paid'])->toBe(300000 - $p['wht'])->and($p['certificate'])->toContain('/wht/');

    $month = now()->format('Y-m');
    $wht = $this->getJson("/office-api/withholding-tax?month={$month}")->assertOk()->json();
    expect($wht)->toMatchArray(['month' => $month, 'total' => $p['wht'], 'enabled' => true])->and($wht['rows'][0])->toMatchArray(['supplier' => 'OSU PROPERTIES', 'tin' => null])
        ->and($wht['due'])->toBe(now()->startOfMonth()->addMonth()->day(15)->toDateString());
    expect($this->getJson('/office-api/withholding-tax')->json('month'))->toBe(now()->subMonth()->format('Y-m')); // last month first
    $csv = $this->get("/office-api/withholding-tax/csv?month={$month}");
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('Supplier TIN')->toContain('OSU PROPERTIES');
});

it('asks to confirm it is you above the limit; a teller sees nothing and an accountant cannot pay', function () {
    $this->actingAs($this->fm, 'web');
    app(Settings::class)->set(actorFor($this->owner), Reconfirm::SETTING, 10000);
    Reconfirm::$assumeConfirmed = false;
    $this->postJson('/office-api/supplier-payments', ($this->pay)())->assertStatus(428)->assertJsonPath('error', 'reconfirm');
    Reconfirm::$assumeConfirmed = true;
    expect(SupplierPayment::count())->toBe(0);

    $this->actingAs($this->accountant, 'web');
    expect($this->getJson('/office-api/supplier-payments/options')->json('pay'))->toBeNull();
    $this->postJson('/office-api/supplier-payments', ($this->pay)())->assertForbidden();
    $teller = staff($this->owner, 'efua', 2, 'teller', ScopeType::Branch, $this->b['HQ']->id);
    $this->actingAs($teller, 'web')->getJson('/office-api/supplier-payments')->assertForbidden();
    $this->getJson('/office-api/aged-payables')->assertForbidden();
    $this->getJson('/office-api/withholding-tax')->assertForbidden();
});
