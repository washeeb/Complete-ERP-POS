<?php

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiReply;
use App\Domain\Purchasing\OrderImporter;
use App\Domain\Purchasing\OrderSender;
use App\Domain\Purchasing\PurchaseOrderService;
use App\Domain\Purchasing\ReorderService;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ReorderSuggestions;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Mail\PlainMessage;
use App\Models\Message;
use App\Models\PurchaseOrder;
use App\Models\ReorderRule;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Support\FakeAiProvider;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00'], baseBarcode: '6001234500012');
    $this->ctn = $this->milo->units->firstWhere('is_base', false);
    $this->sugar = makeProduct($this->owner, 'SUGAR', 'Sugar 1kg', retail: '12.00');
    $this->officer = withMfa(staff($this->owner, 'ama', 2, 'purchasing_officer'));
    $this->manager = withMfa(staff($this->owner, 'kojo', 4, 'warehouse_manager', ScopeType::Branch, $this->branches['HQ']->id));
    $this->nestle = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => 'Nestlé Ghana', 'email' => 'orders@nestle.test', 'phone' => '0241112222',
        'products' => [['product_uom_id' => $this->ctn->id, 'supplier_sku' => 'N-24', 'last_cost' => '240', 'is_preferred' => true]]]);
    $this->other = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => 'Kama Distributors']);
});

afterEach(fn () => AiGateway::fake(null));

function sheet(string $csv): string
{
    $path = tempnam(sys_get_temp_dir(), 'po').'.csv';
    file_put_contents($path, $csv);

    return $path;
}

it('makes a draft order from an Excel or CSV list, matching by supplier code, barcode and name, listing what it cannot use', function () {
    $r = app(OrderImporter::class)->fromSheet(actorFor($this->officer), $this->nestle, $this->hq,
        sheet("Supplier code,Product,Qty,Unit,Cost\nN-24,,10,,\n,sugar 1kg,5,,11.50\n,Unknown item,3,,1\n,Sugar 1kg,0,,\n"), 'list.csv');
    $po = $r['order'];
    expect($po->status)->toBe('draft')->and($po->supplier_id)->toBe($this->nestle->id)->and($po->lines)->toHaveCount(2)
        ->and($po->lines[0]->product_uom_id)->toBe($this->ctn->id)->and($po->lines[0]->unit_cost_minor)->toBe(24000) // the supplier's carton and last cost
        ->and($po->lines[1]->name)->toBe('SUGAR 1KG')->and($po->lines[1]->unit_cost_minor)->toBe(1150)
        ->and($r['skipped'])->toBe(['Line 4 (Unknown item): no matching product', 'Line 5 (Sugar 1kg): no quantity']);

    // A barcode finds the product; with no cost given and none known from this supplier, the line is listed, not guessed.
    $r = app(OrderImporter::class)->fromSheet(actorFor($this->officer), $this->other, $this->hq, sheet("Barcode,SKU,Qty,Price\n6001234500012,,24,1.20\n\n,SUGAR,2,\n"), 'b.csv');
    expect($r['order']->lines)->toHaveCount(1)->and($r['order']->lines[0]->product_id)->toBe($this->milo->id)
        ->and($r['skipped'])->toBe(['Line 4 (SUGAR): no cost given and none known from KAMA DISTRIBUTORS']);

    expect(fn () => app(OrderImporter::class)->fromSheet(actorFor($this->officer), $this->other, $this->hq, sheet("Product,Qty\nNothing,1\n"), 'c.csv'))
        ->toThrow(DomainException::class, 'No line could be used');
});

it('reads a pro-forma invoice with the AI into a draft order for the supplier on it', function () {
    AiGateway::fake(new FakeAiProvider([new AiReply(json_encode(['supplier_name' => 'Nestle Ghana Limited', 'proforma_number' => 'PF-881',
        'lines' => [['description' => 'MILO 400G CARTON', 'sku' => 'N-24', 'qty' => 6, 'unit' => 'CTN', 'unit_price' => 236.5],
            ['description' => 'Sugar 1kg', 'sku' => null, 'qty' => 10, 'unit' => null, 'unit_price' => 11]]]))]));
    $path = 'purchase-imports/pf.png';
    Storage::disk('local')->put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));

    $r = app(OrderImporter::class)->fromProforma(actorFor($this->officer), $this->hq, null, $path, 'pf.png');
    expect($r['order']->supplier_id)->toBe($this->nestle->id)->and($r['skipped'])->toBe([])
        ->and($r['order']->lines->pluck('unit_cost_minor')->all())->toBe([23650, 1100])
        ->and($r['order']->note)->toContain('PF-881');
});

it('orders again from an earlier order at the latest costs', function () {
    $po = app(PurchaseOrderService::class)->saveDraft(actorFor($this->officer), new PurchaseOrder, ['supplier_id' => $this->nestle->id, 'warehouse_id' => $this->hq->id,
        'lines' => [['product_uom_id' => $this->ctn->id, 'qty' => '4'], ['product_uom_id' => $this->sugar->units->first()->id, 'qty' => '10', 'unit_cost' => '11.00']]]);
    app(PurchaseOrderService::class)->cancel(actorFor($this->officer), $po, 'Wrong store');

    $copy = app(PurchaseOrderService::class)->copy(actorFor($this->officer), $po->fresh());
    expect($copy->id)->not->toBe($po->id)->and($copy->status)->toBe('draft')->and($copy->note)->toBe("Ordered again from {$po->number}")
        ->and($copy->lines->pluck('qty')->map(fn ($q) => (string) $q)->all())->toBe(['4.000', '10.000'])
        ->and($copy->lines->pluck('unit_cost_minor')->all())->toBe([24000, 1100]);
});

it('sends an approved order to the supplier by e-mail with the PDF, or opens WhatsApp with a download link', function () {
    Mail::fake();
    $svc = app(PurchaseOrderService::class);
    $po = $svc->saveDraft(actorFor($this->officer), new PurchaseOrder, ['supplier_id' => $this->nestle->id, 'warehouse_id' => $this->hq->id,
        'lines' => [['product_uom_id' => $this->ctn->id, 'qty' => '4']]]);
    expect(fn () => app(OrderSender::class)->send(actorFor($this->officer), $po, 'email', 'orders@nestle.test'))->toThrow(DomainException::class, 'only an approved order');
    $svc->submit(actorFor($this->officer), $po);
    $po = $svc->approve(actorFor($this->manager), $po->fresh());

    $r = app(OrderSender::class)->send(actorFor($this->officer), $po, 'email', 'orders@nestle.test', 'Deliver in the morning, please.');
    Mail::assertSent(PlainMessage::class, fn ($m) => $m->attachmentName === "{$po->number}.pdf" && str_contains($m->text, 'Deliver in the morning'));
    expect(Storage::disk('local')->get($r['order']->pdf_path))->toStartWith('%PDF')
        ->and($r['order']->sent_via)->toBe('email')->and($r['order']->sent_to)->toBe('orders@nestle.test')
        ->and(Message::where('reference', $po->number)->value('channel'))->toBe('email');

    // Without a WhatsApp provider, WhatsApp opens on this computer with the message and a signed download link.
    expect(app(OrderSender::class)->channels($this->tenant->id))->not->toHaveKey('whatsapp');
    $r = app(OrderSender::class)->send(actorFor($this->officer), $po->fresh(), 'whatsapp_link', '0241112222');
    expect($r['open'])->toStartWith('https://wa.me/233241112222?text=')->and(urldecode($r['open']))->toContain($po->number);
    $link = URL::temporarySignedRoute('purchase-order.pdf', now()->addDay(), ['po' => $po->id]);
    $this->get($link)->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get(route('purchase-order.pdf', ['po' => $po->id]))->assertForbidden(); // not signed

    // From the screen: the button sends and the order shows it went.
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->officer);
    Livewire::test(ViewPurchaseOrder::class, ['record' => $po->id])->assertActionVisible('send')
        ->callAction('send', ['channel' => 'email', 'to' => 'orders@nestle.test'])->assertHasNoActionErrors();
    $this->get(PurchaseOrderResource::getUrl('view', ['record' => $po]))->assertSee('E-mail (PDF attached) to orders@nestle.test');
});

it('lets the buyer change the supplier, unit, quantity and levels on What to order', function () {
    app(ReorderService::class)->saveRule(actorFor($this->manager), $this->hq, $this->milo, '24', '96');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->manager);
    $base = $this->milo->units->firstWhere('is_base', true);

    Livewire::test(ReorderSuggestions::class)->assertSee('NESTLÉ GHANA')
        ->filterTable('supplier_id', $this->other->id)->assertDontSee('MILO 400G')
        ->resetTableFilters()
        ->callTableAction('change', $this->milo->id, ['supplier_id' => $this->other->id, 'product_uom_id' => $base->id, 'qty' => '50', 'cost' => '13.75', 'min' => '30', 'max' => '120'])
        ->assertHasNoTableActionErrors()->assertNotified('Levels saved')
        ->assertSee('KAMA DISTRIBUTORS')->assertSee('Changed by you')
        ->filterTable('supplier_id', $this->other->id)->assertSee('MILO 400G')
        ->callTableBulkAction('order', [$this->milo->id])->assertNotified('1 draft purchase order(s) created');

    $po = PurchaseOrder::sole();
    expect($po->supplier_id)->toBe($this->other->id)->and($po->lines[0]->product_uom_id)->toBe($base->id)->and((string) $po->lines[0]->qty)->toBe('50.000')
        ->and($po->lines[0]->unit_cost_minor)->toBe(1375) // no cost from Kama yet: typed by the buyer
        ->and((string) ReorderRule::where('product_id', $this->milo->id)->value('max_qty'))->toBe('120.000');
});

it('makes the draft from the purchase orders screen and opens it', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->officer);
    $file = UploadedFile::fake()->createWithContent('order.csv', "SKU,Qty\nMILO,48\nNOPE,1\n");
    Livewire::test(ListPurchaseOrders::class)
        ->callAction('import', ['source' => 'sheet', 'supplier_id' => $this->nestle->id, 'warehouse_id' => $this->hq->id, 'file' => $file])
        ->assertHasNoActionErrors()->assertRedirect(PurchaseOrderResource::getUrl('edit', ['record' => PurchaseOrder::sole()]));
    expect(PurchaseOrder::sole()->lines)->toHaveCount(1);
});
