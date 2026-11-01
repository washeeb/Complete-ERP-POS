<?php

use App\Domain\Purchasing\GoodsReceiptService;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Purchasing\SupplierOnboarding;
use App\Domain\Purchasing\SupplierScorecard;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\DomainException;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00']);
    $this->ctn = $this->milo->units->firstWhere('is_base', false);
    $this->buyer = staff($this->owner, 'ama', 2, 'purchasing_officer');
    $this->gm = staff($this->owner, 'kojo', 5, 'general_manager');
    $this->keeper = staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->branches['HQ']->id);
    $this->manager = staff($this->owner, 'esi', 4, 'warehouse_manager', ScopeType::Branch, $this->branches['HQ']->id);
    $this->onboarding = app(SupplierOnboarding::class);
    $this->draft = fn (Supplier $s) => app(PurchaseOrderService::class)->saveDraft(actorFor($this->buyer), new PurchaseOrder, ['supplier_id' => $s->id,
        'warehouse_id' => $this->hq->id, 'lines' => [['product_uom_id' => $this->ctn->id, 'qty' => '2', 'unit_cost' => '240.00']]]);
});

it('lets a one-person business order from a supplier as soon as it is added (controls off), but never from a blocked one', function () {
    $s = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => 'Kofi Wholesale']);
    expect($s->status)->toBe('approved');
    ($this->draft)($s);

    $this->onboarding->block(actorFor($this->gm), $s, 'Sold expired stock');
    expect(fn () => ($this->draft)($s->fresh()))->toThrow(DomainException::class, 'blocked: Sold expired stock');
    $this->onboarding->unblock(actorFor($this->gm), $s->fresh(), 'Settled');
    expect($s->fresh()->status)->toBe('approved');
});

it('with supplier controls on: a second person approves, with the TIN and required documents in date, before any order', function () {
    app(Settings::class)->set(actorFor($this->owner), 'purchasing.supplier_approval', true);
    $s = app(SupplierService::class)->save(actorFor($this->buyer), new Supplier, ['name' => 'Ernest Chemists']);
    expect($s->status)->toBe('pending');
    expect(fn () => ($this->draft)($s))->toThrow(DomainException::class, 'waiting for approval');

    // Not the person who added it; not without the documents and the TIN.
    expect(fn () => $this->onboarding->approve(actorFor($this->buyer), $s))->toThrow(Exception::class);
    expect(fn () => $this->onboarding->approve(actorFor($this->gm), $s))->toThrow(DomainException::class, 'TIN certificate (GRA) (missing), Business registration (ORC) (missing)');
    $this->onboarding->addDocument(actorFor($this->buyer), $s, ['kind' => 'tin_certificate', 'number' => 'C0012345678'], null, null);
    $reg = $this->onboarding->addDocument(actorFor($this->buyer), $s, ['kind' => 'business_registration', 'number' => 'CS123', 'expires_on' => now()->addMonths(6)->toDateString()], null, null);
    expect(fn () => $this->onboarding->approve(actorFor($this->gm), $s))->toThrow(DomainException::class, 'TIN first');
    app(SupplierService::class)->save(actorFor($this->buyer), $s, ['tax_no' => 'C0012345678']);
    $this->onboarding->approve(actorFor($this->gm), $s->fresh());
    expect($s->fresh())->status->toBe('approved')->approved_by_name->toBe('Kojo');
    ($this->draft)($s->fresh());

    // A required document expires: the buyers are told once, and orders stop.
    expect(fn () => $this->onboarding->verifyDocument(actorFor($this->buyer), $reg))->toThrow(Exception::class);
    $this->onboarding->verifyDocument(actorFor($this->gm), $reg);
    $reg->forceFill(['expires_on' => now()->subDay()->toDateString()])->saveQuietly();
    expect($this->onboarding->expiryAlerts($this->tenant->id))->toBe(1)->and($this->onboarding->expiryAlerts($this->tenant->id))->toBe(0)
        ->and($this->gm->notifications()->first()->data['title'])->toBe('Expired: ERNEST CHEMISTS, Business registration (ORC)');
    expect(fn () => ($this->draft)($s->fresh()))->toThrow(DomainException::class, 'Business registration (ORC) (expired');
});

it('with supplier controls on: no transfer or MoMo goes to bank details nobody else has verified', function () {
    app(Settings::class)->set(actorFor($this->owner), 'purchasing.supplier_approval', true);
    $s = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => 'Tobinco']);
    app(SupplierService::class)->save(actorFor($this->buyer), $s, ['bank_name' => 'GCB', 'bank_account_no' => '1234567890']);
    expect(fn () => $this->onboarding->assertBankVerified($s->fresh(), 'bank'))->toThrow(DomainException::class, 'nobody has checked');
    $this->onboarding->assertBankVerified($s->fresh(), 'cash'); // cash is not affected

    expect(fn () => $this->onboarding->verifyBank(actorFor($this->buyer), $s->fresh(), 'Called them'))->toThrow(Exception::class);
    $this->onboarding->verifyBank(actorFor($this->gm), $s->fresh(), 'Called Mr Tobin on the number from our first order');
    $this->onboarding->assertBankVerified($s->fresh(), 'bank');

    // A changed account number goes back for checking.
    app(SupplierService::class)->save(actorFor($this->buyer), $s->fresh(), ['bank_account_no' => '9999999999']);
    expect($s->fresh()->bank_verified_at)->toBeNull()
        ->and(DB::table('audit_events')->where('subject_id', $s->id)->where('action', 'supplier.bank_changed')->count())->toBe(2);
});

it('scores a supplier from what happened: on time, fill rate, lead time', function () {
    $s = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => 'Nestlé Ghana']);
    expect(SupplierScorecard::for($s)['score'])->toBeNull();

    $po = ($this->draft)($s);
    $po->forceFill(['expected_on' => now()->addDays(3)->toDateString()])->save();
    app(PurchaseOrderService::class)->submit(actorFor($this->buyer), $po);
    $po = app(PurchaseOrderService::class)->approve(actorFor($this->manager), $po->fresh());
    app(GoodsReceiptService::class)->receive(actorFor($this->keeper), $po, ['lines' => [['purchase_order_line_id' => $po->lines[0]->id, 'qty' => '2']]]);

    $c = SupplierScorecard::for($s);
    expect($c)->orders->toBe(1)->on_time->toBe(100)->fill->toBe(100)->grade->toBe('A')->and($c['lead_days'])->toBeLessThan(1);
});
