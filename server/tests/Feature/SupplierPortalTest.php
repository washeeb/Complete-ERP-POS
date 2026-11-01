<?php

use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Purchasing\SupplierOnboarding;
use App\Domain\Purchasing\SupplierPortal;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\DomainException;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00']);
    $this->supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => 'Kofi Wholesale']);
    $this->other = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => 'Accra Traders']);
    $this->buyer = staff($this->owner, 'ama', 2, 'purchasing_officer');
    $this->accountant = staff($this->owner, 'esi', 4, 'accountant');
    $manager = staff($this->owner, 'kojo', 4, 'warehouse_manager', ScopeType::Branch, $this->branches['HQ']->id);
    $svc = app(PurchaseOrderService::class);
    $po = $svc->saveDraft(actorFor($this->buyer), new PurchaseOrder, ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->hq->id,
        'lines' => [['product_uom_id' => $milo->units->firstWhere('is_base', false)->id, 'qty' => '10', 'unit_cost' => '240.00']]]);
    $svc->submit(actorFor($this->buyer), $po);
    $this->po = $svc->approve(actorFor($manager), $po->fresh());
    $this->portal = app(SupplierPortal::class);
});

it('stays off until switched on, then gives each supplier a private page that a new link or a block shuts', function () {
    expect(fn () => $this->portal->link(actorFor($this->buyer), $this->supplier))->toThrow(DomainException::class, 'portal is off');
    app(Settings::class)->set(actorFor($this->owner), 'purchasing.supplier_portal', true);

    $link = $this->portal->link(actorFor($this->buyer), $this->supplier);
    $token = Supplier::find($this->supplier->id)->portal_token;
    expect($link)->toEndWith("/s/{$token}")
        ->and($this->portal->link(actorFor($this->buyer), $this->supplier->fresh()))->toBe($link) // the same link until renewed
        ->and(DB::table('audit_events')->where('action', 'supplier.portal_link')->count())->toBe(1)
        ->and(DB::table('audit_events')->where('subject_id', $this->supplier->id)->get()->toJson())->not->toContain($token);

    $this->get($link)->assertOk()->assertSee($this->supplier->name)->assertSee($this->po->number);
    $this->get(route('supplier.portal.order', [$token, $this->po->id]))->assertOk()->assertSee('Confirm the order');

    $new = $this->portal->link(actorFor($this->buyer), $this->supplier->fresh(), renew: true);
    $this->get($link)->assertNotFound();
    $this->get($new)->assertOk();

    app(SupplierOnboarding::class)->block(actorFor($this->owner), $this->supplier->fresh(), 'Licence suspended');
    $this->get($new)->assertNotFound();
});

it('lets the supplier confirm an order, say the goods are on the way, and send an invoice that accounts enters or turns back', function () {
    Storage::fake('local');
    app(Settings::class)->set(actorFor($this->owner), 'purchasing.supplier_portal', true);
    $this->portal->link(actorFor($this->buyer), $this->supplier);
    $token = Supplier::find($this->supplier->id)->portal_token;
    $order = route('supplier.portal.order', [$token, $this->po->id]);

    $this->post($order, ['action' => 'confirm', 'name' => 'Kofi', 'promised_on' => today()->addDays(3)->toDateString(), 'reply' => 'All in stock'])->assertRedirect();
    $this->post($order, ['action' => 'dispatch', 'name' => 'Kofi', 'reference' => 'DN 4471, GT-1234-20'])->assertRedirect();
    expect($this->po->fresh())->supplier_promised_on->toDateString()->toBe(today()->addDays(3)->toDateString())->supplier_reply->toBe('All in stock')
        ->dispatch_ref->toBe('DN 4471, GT-1234-20')
        ->and(DB::table('audit_events')->where('action', 'purchase_order.supplier_confirmed')->value('actor_name'))->toBe("Kofi ({$this->supplier->name}, supplier portal)");

    $this->post(route('supplier.portal.invoice', $token), ['name' => 'Kofi', 'invoice_no' => 'INV-88', 'invoice_date' => today()->toDateString(), 'amount' => '2760.00',
        'purchase_order_id' => $this->po->id, 'file' => UploadedFile::fake()->create('inv-88.pdf', 120, 'application/pdf')])->assertRedirect()->assertSessionHas('portal_done');
    $inv = SupplierInvoice::firstOrFail();
    expect($inv)->status->toBe('received')->amount_minor->toBe(276000)->purchase_order_id->toBe($this->po->id);
    Storage::disk('local')->assertExists($inv->file_path);

    // The same invoice twice is refused; another supplier's order cannot be named.
    $this->post(route('supplier.portal.invoice', $token), ['name' => 'Kofi', 'invoice_no' => 'inv-88', 'invoice_date' => today()->toDateString(), 'amount' => '1',
        'file' => UploadedFile::fake()->create('again.pdf', 10, 'application/pdf')])->assertSessionHas('portal_error', 'Invoice inv-88 has already been sent to us.');
    expect(fn () => $this->portal->confirm($this->other, $this->po, today()->toDateString(), null, 'Akua'))->toThrow(DomainException::class, 'not one of yours');

    expect(fn () => $this->portal->reject(actorFor($this->buyer), $inv, 'x'))->toThrow(Exception::class);
    $this->portal->reject(actorFor($this->accountant), $inv, 'Prices differ from the order');
    $this->get(route('supplier.portal', $token))->assertSee('Turned back: Prices differ from the order');
});
