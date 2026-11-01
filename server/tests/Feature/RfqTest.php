<?php

use App\Domain\Purchasing\PurchaseRequestService;
use App\Domain\Purchasing\RfqService;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\DomainException;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\Rfqs\RfqResource;
use App\Filament\Resources\SupplierInvoices\SupplierInvoiceResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00']);
    $this->sugar = makeProduct($this->owner, 'SUGAR', 'Sugar 1kg', retail: '20.00', carton: ['10', '180.00']);
    $this->buyer = staff($this->owner, 'ama', 2, 'purchasing_officer');
    $save = fn (string $name) => app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => $name]);
    [$this->a, $this->b, $this->c] = [$save('Kofi Wholesale'), $save('Accra Traders'), $save('Tema Foods')];
    $this->svc = app(RfqService::class);
    $this->rfq = $this->svc->create(actorFor($this->buyer), ['title' => 'Groceries for November', 'warehouse_id' => $this->hq->id, 'closes_at' => now()->addDays(2)->toDateTimeString(),
        'supplier_ids' => [$this->a->id, $this->b->id, $this->c->id], 'lines' => [
            ['product_uom_id' => $this->milo->units->firstWhere('is_base', false)->id, 'qty' => '10'],
            ['product_uom_id' => $this->sugar->units->firstWhere('is_base', false)->id, 'qty' => '5'],
        ]]);
    $this->inv = fn (Supplier $s) => $this->rfq->invitations()->where('supplier_id', $s->id)->first();
    [$this->milo1, $this->sugar1] = [$this->rfq->lines[0]->id, $this->rfq->lines[1]->id];
});

it('keeps quotes sealed until closing, scores them, and awards line by line into draft orders with the reason written down', function () {
    expect($this->rfq)->number->toMatch('/^RFQ-/')->status->toBe('open')->and($this->rfq->invitations)->toHaveCount(3);

    $this->svc->quote(($this->inv)($this->a), ['lines' => [$this->milo1 => ['price' => '300.00'], $this->sugar1 => ['price' => '170.00']],
        'delivery_days' => 2, 'payment_days' => 30], 'Kofi');
    $this->svc->quote(($this->inv)($this->b), ['lines' => [$this->milo1 => ['price' => '310.00'], $this->sugar1 => ['price' => '160.00', 'qty' => '4']],
        'delivery_days' => 5, 'payment_days' => 0], 'Akua');
    $this->svc->decline(($this->inv)($this->c), 'Out of stock', 'Yaw');

    // Sealed: no prices before the closing time, and the audit trail never carries them.
    expect(fn () => $this->svc->compare($this->rfq->fresh()))->toThrow(DomainException::class, 'sealed')
        ->and(DB::table('audit_events')->where('action', 'like', 'rfq.%')->pluck('reason')->implode(' '))->not->toContain('300');

    $this->svc->close(actorFor($this->buyer), $this->rfq->fresh());
    expect(fn () => $this->svc->quote(($this->inv)($this->c), ['lines' => [$this->milo1 => ['price' => '1.00']]], 'Yaw'))->toThrow(DomainException::class, 'closed');

    $cmp = $this->svc->compare($this->rfq->fresh());
    $a = ($this->inv)($this->a)->id;
    $b = ($this->inv)($this->b)->id;
    expect($cmp['lines'][$this->milo1]['cheapest'])->toBe($a)
        ->and($cmp['lines'][$this->sugar1]['cheapest'])->toBe($b)
        ->and($cmp['best'])->toBe($a) // full cover, faster, longer credit
        ->and($cmp['offers'][$a]['score'])->toBeGreaterThan($cmp['offers'][$b]['score'])
        ->and(($this->inv)($this->a)->total_minor)->toBe(385000); // 10 × 300 + 5 × 170

    // Sugar to A is not the cheapest price: the reason is required.
    expect(fn () => $this->svc->award(actorFor($this->buyer), $this->rfq->fresh(), [$this->milo1 => $a, $this->sugar1 => $a]))
        ->toThrow(DomainException::class, 'is not going to the cheapest price');
    $pos = $this->svc->award(actorFor($this->buyer), $this->rfq->fresh(), [$this->milo1 => $a, $this->sugar1 => $a], 'Accra Traders can supply only 4 cartons');

    expect($pos)->toHaveCount(1);
    $po = PurchaseOrder::with('lines')->find($pos[0]->id);
    expect($po)->status->toBe('draft')->supplier_id->toBe($this->a->id)->rfq_id->toBe($this->rfq->id)
        ->and($po->expected_on->toDateString())->toBe(today()->addDays(2)->toDateString())
        ->and($po->lines->pluck('unit_cost_minor')->all())->toBe([30000, 17000])
        ->and($this->rfq->fresh())->status->toBe('awarded')->award_note->toContain('only 4')
        ->and(($this->inv)($this->a)->purchase_order_id)->toBe($po->id);
});

it('splits the award between suppliers, one order each, at the quantity each can supply', function () {
    $this->svc->quote(($this->inv)($this->a), ['lines' => [$this->milo1 => ['price' => '300.00']]], 'Kofi');
    $this->svc->quote(($this->inv)($this->b), ['lines' => [$this->sugar1 => ['price' => '160.00', 'qty' => '4']]], 'Akua', actorFor($this->buyer));
    expect(($this->inv)($this->b)->quoted_by_name)->toBe('Entered by Ama from Akua');

    $this->travel(3)->days(); // the closing time passed: open for comparison without closing by hand
    $pos = $this->svc->award(actorFor($this->buyer), $this->rfq->fresh(), [$this->milo1 => ($this->inv)($this->a)->id, $this->sugar1 => ($this->inv)($this->b)->id]);
    expect($pos)->toHaveCount(2)
        ->and(PurchaseOrder::where('rfq_id', $this->rfq->id)->with('lines')->get()->map(fn ($p) => $p->lines->first()->qty)->sort()->values()->all())->toBe(['4.000', '10.000']);
});

it('asks for prices from a purchase request, and gives the request back to the buyers when cancelled', function () {
    $r = app(PurchaseRequestService::class)->request(actorFor($this->owner), ['warehouse_id' => $this->hq->id,
        'lines' => [['product_uom_id' => $this->milo->units->firstWhere('is_base', false)->id, 'qty' => '6']]]);
    $rfq = $this->svc->fromRequest(actorFor($this->buyer), $r, [$this->a->id, $this->b->id], now()->addDay()->toDateTimeString());
    expect($r->fresh()->status)->toBe('quoting')->and($rfq->purchase_request_id)->toBe($r->id)->and($rfq->lines)->toHaveCount(1);

    $this->svc->cancel(actorFor($this->buyer), $rfq, 'Supplier list changed');
    expect($r->fresh()->status)->toBe('requested')->and($rfq->fresh()->status)->toBe('cancelled');

    $rfq2 = $this->svc->fromRequest(actorFor($this->buyer), $r->fresh(), [$this->a->id], now()->addDay()->toDateTimeString());
    $this->svc->quote($rfq2->invitations->first(), ['lines' => [$rfq2->lines[0]->id => ['price' => '299.99']]], 'Kofi');
    $this->svc->close(actorFor($this->buyer), $rfq2->fresh());
    $pos = $this->svc->award(actorFor($this->buyer), $rfq2->fresh(), [$rfq2->lines[0]->id => $rfq2->invitations->first()->id]);
    expect($r->fresh())->status->toBe('ordered')->purchase_order_id->toBe($pos[0]->id);
});

it('refuses what does not fit', function () {
    $later = now()->addDay()->toDateTimeString();
    $line = [['product_uom_id' => $this->milo->units->firstWhere('is_base', false)->id, 'qty' => '1']];
    expect(fn () => $this->svc->create(actorFor($this->buyer), ['title' => 'x', 'warehouse_id' => $this->hq->id, 'closes_at' => now()->subHour()->toDateTimeString(), 'supplier_ids' => [$this->a->id], 'lines' => $line]))
        ->toThrow(DomainException::class, 'closing time must be in the future')
        ->and(fn () => $this->svc->create(actorFor($this->buyer), ['title' => 'x', 'warehouse_id' => $this->hq->id, 'closes_at' => $later, 'supplier_ids' => [], 'lines' => $line]))
        ->toThrow(DomainException::class, 'Choose the suppliers')
        ->and(fn () => $this->svc->create(actorFor(staff($this->owner, 'yaw', 2, 'storekeeper')), ['title' => 'x', 'warehouse_id' => $this->hq->id, 'closes_at' => $later, 'supplier_ids' => [$this->a->id], 'lines' => $line]))
        ->toThrow(Exception::class)
        ->and(fn () => $this->svc->quote(($this->inv)($this->a), ['lines' => [$this->milo1 => ['price' => '300', 'qty' => '11']]], 'Kofi'))
        ->toThrow(DomainException::class, 'at most 10')
        ->and(fn () => $this->svc->quote(($this->inv)($this->a), ['lines' => []], 'Kofi'))
        ->toThrow(DomainException::class, 'at least one line')
        ->and(fn () => $this->svc->award(actorFor($this->buyer), $this->rfq->fresh(), []))
        ->toThrow(DomainException::class, 'sealed');
});

it('lets the supplier quote on their own link, without signing in, until the closing time', function () {
    $inv = ($this->inv)($this->a);
    $this->get(route('rfq.quote', $inv->token))->assertOk()->assertSee('Groceries for November')->assertSee($this->milo->name);
    expect($inv->fresh()->viewed_at)->not->toBeNull();

    $this->post(route('rfq.quote', $inv->token), ['action' => 'quote', 'name' => 'Kofi', 'delivery_days' => 3,
        'lines' => [$this->milo1 => ['price' => '300', 'qty' => '10'], $this->sugar1 => ['price' => '', 'qty' => '5']]])
        ->assertOk()->assertSee('your prices are in');
    expect($inv->fresh())->status->toBe('quoted')->total_minor->toBe(300000)->quoted_by_name->toBe('Kofi');

    $this->get(route('rfq.quote', str_repeat('x', 40)))->assertNotFound();
    $this->travel(3)->days();
    $this->post(route('rfq.quote', $inv->token), ['action' => 'quote', 'name' => 'Kofi', 'lines' => [$this->milo1 => ['price' => '1']]])
        ->assertOk()->assertSee('has closed');
    expect(Rfq::find($this->rfq->id)->status)->toBe('open');
});

it('shows the request for quotation screens, sealed and then side by side', function () {
    $this->svc->quote(($this->inv)($this->a), ['lines' => [$this->milo1 => ['price' => '300.00'], $this->sugar1 => ['price' => '170.00']]], 'Kofi');
    $this->svc->quote(($this->inv)($this->b), ['lines' => [$this->milo1 => ['price' => '310.00']]], 'Akua');
    $this->actingAs(withMfa($this->owner));
    foreach ([RfqResource::getUrl('index'), RfqResource::getUrl('create'), SupplierInvoiceResource::getUrl('index'), SupplierResource::getUrl('index')] as $url) {
        $this->get($url)->assertOk();
    }
    $view = RfqResource::getUrl('view', ['record' => $this->rfq]);
    $this->get($view)->assertOk()->assertSee('Groceries for November')->assertSee('Quoted')->assertDontSee('GH₵ 300.00')->assertDontSee('Comparison');

    $this->svc->close(actorFor($this->buyer), $this->rfq->fresh());
    $this->get($view)->assertOk()->assertSee('Comparison')->assertSee('GH₵ 300.00 ★')->assertSee('Score');
    $pos = $this->svc->award(actorFor($this->buyer), $this->rfq->fresh(), [$this->milo1 => ($this->inv)($this->a)->id]);
    $this->get(PurchaseOrderResource::getUrl('view', ['record' => $pos[0]]))->assertOk()->assertSee('Supplier confirmed');
});
