<?php

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiReply;
use App\Domain\Ai\InvoiceScanner;
use App\Domain\Catalog\CatalogService;
use App\Domain\Fashion\ConsignmentService;
use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\GlCheck;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockAdjustmentService;
use App\Domain\Purchasing\DirectPurchases;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ConsignmentToPay;
use App\Filament\Resources\DirectPurchases\DirectPurchaseResource;
use App\Filament\Resources\DirectPurchases\Pages\CreateDirectPurchase;
use App\Filament\Resources\DirectPurchases\Pages\EditDirectPurchase;
use App\Filament\Resources\DirectPurchases\Pages\ListDirectPurchases;
use App\Filament\Resources\DirectPurchases\Pages\ViewDirectPurchase;
use App\Models\Account;
use App\Models\DirectPurchase;
use App\Models\Price;
use App\Models\ProductUom;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FakeAiProvider;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->a = actorFor($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->svc = app(DirectPurchases::class);
    app(Settings::class)->set($this->a, 'purchasing.direct_approval_minor', 100000000);
    $this->supplier = app(SupplierService::class)->save($this->a, new Supplier, ['code' => 'ECP', 'name' => 'Sample Wholesale Pharmacy', 'payment_terms_days' => 30]);
    $this->med = makeProduct($this->owner, 'MED1', 'Multivitamin Caps', retail: '300.00')->fresh(['units']);
    $this->base = $this->med->units->firstWhere('is_base', true);
    $this->momo = Account::where('type', 'asset')->where('is_header', false)->whereNull('control')->where('system_key', 'bank')->first()
        ?? Account::where('type', 'asset')->where('is_header', false)->whereNull('control')->first();
    $this->doc = fn (array $over = [], array $line = []) => $this->svc->save($this->a, new DirectPurchase, $over + ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->hq->id, 'invoice_no' => 'PSI-1',
        'invoice_date' => now()->toDateString(), 'settlement' => 'paid', 'account_id' => $this->momo->id, 'method' => 'cash',
        'lines' => [$line + ['product_uom_id' => $this->base->id, 'qty' => '5', 'unit_price' => '195.36', 'tax_group_id' => null]]]);
    $this->gl = fn (string $key) => (int) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.system_key', $key)->sum('l.amount_minor');
    $this->ties = function () {
        app(AutoPoster::class)->run($this->tenant->id);

        return collect(app(GlCheck::class)->run($this->tenant->id)['ties'])->keyBy('control');
    };
});

it('turns a paid invoice into goods received, a bill and a payment', function () {
    app(CatalogService::class)->saveProduct($this->a, $this->med, ['track_batches' => true]);
    $doc = ($this->doc)(['prices_include_tax' => false], ['batch_no' => 'WLM2605', 'expires_on' => now()->addYears(2)->toDateString(), 'supplier_sku' => 'OTC-11598', 'new_price' => '320.00']);
    expect($doc->status)->toBe('draft')->and($doc->net_minor)->toBe(97680)
        ->and((float) DB::table('stock_levels')->where('warehouse_id', $this->hq->id)->where('product_id', $this->med->id)->value('on_hand'))->toBe(0.0);

    $posted = $this->svc->post($this->a, $doc);
    expect($posted->status)->toBe('posted')->and($posted->goods_receipt_id)->not->toBeNull()->and($posted->supplier_bill_id)->not->toBeNull()->and($posted->supplier_payment_id)->not->toBeNull();

    $level = DB::table('stock_levels')->where('warehouse_id', $this->hq->id)->where('product_id', $this->med->id)->first();
    expect((float) $level->on_hand)->toBe(5.0)->and((float) $level->avg_cost_minor)->toBe(19536.0)
        ->and((int) DB::table('supplier_balances')->where('supplier_id', $this->supplier->id)->value('balance_minor'))->toBe(0)
        ->and(DB::table('supplier_payments')->where('id', $posted->supplier_payment_id)->value('amount_minor'))->toBe($posted->total_minor)
        ->and(DB::table('stock_batches')->where('product_id', $this->med->id)->value('batch_no'))->not->toBeNull()
        ->and(SupplierProduct::where('supplier_id', $this->supplier->id)->first()->supplier_sku)->toBe('OTC-11598')
        ->and(Price::where('product_uom_id', $this->base->id)->where('min_qty', 1)->orderByDesc('updated_at')->first()->amount_minor)->toBe(32000);

    expect($ties = ($this->ties)())->not->toBeEmpty()->and($ties['inventory']['difference'])->toBe(0)->and($ties['payables']['difference'])->toBe(0);
});

it('leaves a credit invoice owing, and works out VAT included or excluded', function () {
    $doc = ($this->doc)(['settlement' => 'credit', 'prices_include_tax' => true, 'printed_total' => '976.80', 'due_on' => now()->addDays(14)->toDateString()]);
    expect($doc->total_minor)->toBe(97680)->and($doc->net_minor + $doc->tax_minor)->toBe(97680);
    $posted = $this->svc->post($this->a, $doc);
    expect($posted->supplier_payment_id)->toBeNull()
        ->and((int) DB::table('supplier_balances')->where('supplier_id', $this->supplier->id)->value('balance_minor'))->toBeGreaterThan(0)
        ->and(DB::table('supplier_bills')->where('id', $posted->supplier_bill_id)->value('due_on'))->toContain(now()->addDays(14)->toDateString());
});

it('flags a total that does not match, short-dated goods and a thin margin', function () {
    $doc = ($this->doc)(['printed_total' => '100.00'], ['batch_no' => 'B1', 'expires_on' => now()->addMonth()->toDateString(), 'new_price' => '200.00']);
    $w = implode(' ', $this->svc->warnings($doc));
    expect($w)->toContain('the invoice says')->toContain('within 6 months')->toContain('below 10%')->toContain('does not track batches');
});

it('refuses an invoice entered twice, even in another case, and expired goods', function () {
    $doc = ($this->doc)();
    expect(fn () => ($this->doc)(['invoice_no' => 'psi-1']))->toThrow(DomainException::class, 'already entered');
    $this->svc->cancel($this->a, $doc, 'Mistake');
    expect(($this->doc)()->number)->not->toBe($doc->number);
    expect(fn () => ($this->doc)(['invoice_no' => 'PSI-2'], ['batch_no' => 'OLD', 'expires_on' => now()->subDay()->toDateString()]))->toThrow(DomainException::class, 'already expired');
});

it('lets stock staff enter and a manager approve above the limit, and nobody approves their own', function () {
    app(Settings::class)->set($this->a, 'purchasing.direct_approval_minor', 50000);
    $store = staff($this->owner, 'storeman', 2, 'storekeeper', ScopeType::Tenant, $this->tenant->id);
    $saved = $this->svc->save(actorFor($store), new DirectPurchase, ['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->hq->id, 'invoice_no' => 'PSI-9', 'invoice_date' => now()->toDateString(),
        'settlement' => 'credit', 'lines' => [['product_uom_id' => $this->base->id, 'qty' => '5', 'unit_price' => '195.36']]]);
    expect(fn () => $this->svc->post(actorFor($store), $saved))->toThrow(DomainException::class, 'send it for approval');
    $saved = $this->svc->submit(actorFor($store), $saved);
    expect(fn () => $this->svc->post(actorFor($store), $saved))->toThrow(DomainException::class, 'manager');

    // A manager who may neither bill nor pay can still post it: the approval is this document's own.
    $gm = withMfa(staff($this->owner, 'gm', 5, 'branch_manager', ScopeType::Tenant, $this->tenant->id));
    expect($this->svc->post(actorFor($gm), $saved)->status)->toBe('posted');

    $it = staff($this->owner, 'itperson', 4, 'it_admin', ScopeType::Tenant, $this->tenant->id);
    expect(fn () => $this->svc->save(actorFor($it), new DirectPurchase, ['warehouse_id' => $this->hq->id]))->toThrow(AccessDenied::class);
});

it('takes consignment in at no cost, owes the agreed cost when sold, and refuses own stock', function () {
    $cons = makeProduct($this->owner, 'CONS', 'On consignment', retail: '110.00')->fresh(['units']);
    $unit = $cons->units->firstWhere('is_base', true);
    $doc = ($this->doc)(['settlement' => 'consignment'], ['product_uom_id' => $unit->id, 'qty' => '5', 'unit_price' => '61.16']);
    $posted = $this->svc->post($this->a, $doc);
    $product = $cons->fresh();
    expect($posted->supplier_bill_id)->toBeNull()->and($product->consignor_id)->toBe($this->supplier->id)->and($product->consignment_cost_minor)->toBe(6116)
        ->and((float) DB::table('stock_levels')->where('warehouse_id', $this->hq->id)->where('product_id', $cons->id)->value('on_hand'))->toBe(5.0)
        ->and(GlCheck::subLedgers($this->tenant->id)['inventory'][1])->toBe(0);

    // A sale owes the agreed cost, not a share of the price.
    expect(ConsignmentService::share((object) ['qty' => '2.000', 'factor' => '1', 'net_minor' => 22000, 'consignment_bp' => null, 'consignment_cost_minor' => 6116]))->toBe(12232)
        ->and(ConsignmentService::share((object) ['qty' => '1.000', 'factor' => '1', 'net_minor' => 10000, 'consignment_bp' => 6000, 'consignment_cost_minor' => null]))->toBe(6000);

    $own = makeProduct($this->owner, 'OWN', 'Own stock', retail: '10.00')->fresh(['units']);
    app(InventoryService::class)->adjust($this->a, $this->hq, $own, $own->units->firstWhere('is_base', true), '1', 'opening', '', null, '5.00');
    $bad = ($this->doc)(['settlement' => 'consignment', 'invoice_no' => 'PSI-3'], ['product_uom_id' => $own->units->firstWhere('is_base', true)->id, 'qty' => '1', 'unit_price' => '5.00']);
    expect(fn () => $this->svc->post($this->a, $bad))->toThrow(DomainException::class, 'stock of your own');
});

it('books stock received with a cost and no invoice to the clearing account', function () {
    app(InventoryService::class)->adjust($this->a, $this->hq, $this->med, $this->base, '2', 'adjustment', 'received_costed', 'Gift from a supplier', '50.00');
    expect(fn () => app(InventoryService::class)->adjust($this->a, $this->hq, $this->med, $this->base, '2', 'adjustment', 'received_costed', 'No cost'))->toThrow(DomainException::class, 'its cost');
    $ties = ($this->ties)();
    expect($ties['inventory']['difference'])->toBe(0)->and(($this->gl)('stock_to_clear'))->toBe(-10000)->and(($this->gl)('stock_adjustment'))->toBe(0);

    $doc = app(StockAdjustmentService::class)->create($this->a, $this->hq, ['reason_code' => 'received_costed']);
    expect($doc->reason_code)->toBe('received_costed');
});

it('reads every page of an invoice into the form, with lots and expiry, and keeps what it could not match', function () {
    Storage::fake('local');
    Storage::disk('local')->put('direct-purchases/p1.png', UploadedFile::fake()->image('p1.png')->getContent());
    Storage::disk('local')->put('direct-purchases/p2.png', UploadedFile::fake()->image('p2.png')->getContent());
    SupplierProduct::create(['supplier_id' => $this->supplier->id, 'product_id' => $this->med->id, 'product_uom_id' => $this->base->id, 'supplier_sku' => 'OTC-11598']);
    AiGateway::fake(new FakeAiProvider([
        new AiReply(json_encode(['supplier_name' => 'Sample Wholesale Pharmacy', 'invoice_number' => 'PSI-586040', 'invoice_date' => now()->toDateString(), 'lines' => [
            ['sku' => 'OTC-11598', 'description' => 'WELLMAN', 'qty' => 5, 'lot' => 'WLM2605', 'expiry_date' => '2028-07-31', 'unit_price' => 195.36, 'line_total' => 976.80]]])),
        new AiReply(json_encode(['total' => 1099.08, 'lines' => [['sku' => 'NEW-1', 'description' => 'KY LUBRICATING JELLY 82G', 'qty' => 2, 'lot' => '8269860', 'expiry_date' => '2028-12-31', 'unit_price' => 61.14, 'line_total' => 122.28]]])),
    ]));
    $form = app(InvoiceScanner::class)->scanPurchase($this->a, $this->branches['HQ'], ['direct-purchases/p1.png', 'direct-purchases/p2.png']);
    AiGateway::fake(null);
    expect($form['supplier_id'])->toBe($this->supplier->id)->and($form['invoice_no'])->toBe('PSI-586040')->and($form['printed_total'])->toEqual(1099.08)
        ->and($form['lines'])->toHaveCount(2)->and($form['lines'][0]['product_uom_id'])->toBe($this->base->id)->and($form['lines'][0]['batch_no'])->toBe('WLM2605')
        ->and($form['lines'][1]['product_uom_id'])->toBeNull()->and($form['lines'][1]['read_as'])->toBe('KY LUBRICATING JELLY 82G')->and($form['attachments'])->toHaveCount(2);
});

it('shows the screens: enter, view, post, list and consignment to pay', function () {
    $this->actingAs(withMfa($this->owner), 'web');
    Livewire::test(CreateDirectPurchase::class)->fillForm(['supplier_id' => $this->supplier->id, 'invoice_no' => 'PSI-7', 'invoice_date' => now()->toDateString(), 'warehouse_id' => $this->hq->id,
        'settlement' => 'paid', 'method' => 'cash', 'account_id' => $this->momo->id,
        'lines' => [['product_uom_id' => $this->base->id, 'qty' => '2', 'unit_price' => '100']]])->call('create')->assertHasNoFormErrors();
    $doc = DirectPurchase::first();
    expect($doc->net_minor)->toBe(20000);
    Livewire::test(ListDirectPurchases::class)->assertSuccessful()->assertSee('PSI-7');
    Livewire::test(ViewDirectPurchase::class, ['record' => $doc->id])->assertSuccessful()->callAction('post')->assertHasNoActionErrors();
    expect($doc->fresh()->status)->toBe('posted');
    Livewire::test(ConsignmentToPay::class)->assertSuccessful()->assertSee('Nothing is owed');
});

it('edits a draft, shows what to check, and adds a product with its pack size on the spot', function () {
    $this->actingAs(withMfa($this->owner), 'web');
    $doc = ($this->doc)(['printed_total' => '100.00'], ['new_price' => '200.00']);
    Livewire::test(ViewDirectPurchase::class, ['record' => $doc->id])->assertSuccessful()->assertSee('Check before posting')->assertSee('the invoice says');
    Livewire::test(EditDirectPurchase::class, ['record' => $doc->id])->assertSuccessful()->assertFormSet(['invoice_no' => 'PSI-1', 'printed_total' => '100.00'])
        ->fillForm(['invoice_no' => 'PSI-1B'])->call('save')->assertHasNoFormErrors();
    expect($doc->fresh()->invoice_no)->toBe('PSI-1B')->and($doc->lines()->count())->toBe(1);

    $add = new ReflectionMethod(DirectPurchaseResource::class, 'addProduct');
    $tablet = Uom::firstOrCreate(['code' => 'TAB'], ['name' => 'Tablet', 'decimals' => 0]);
    $box = Uom::firstOrCreate(['code' => 'BOX'], ['name' => 'Box', 'decimals' => 0]);
    $unit = ProductUom::find($add->invoke(null, ['name' => 'Misoprostol 200mcg', 'base_uom_id' => $tablet->id, 'pack_uom_id' => $box->id, 'pack_factor' => 60,
        'tax_group_id' => $this->med->tax_group_id, 'track_batches' => true]));
    expect((float) $unit->factor)->toBe(60.0)->and($unit->uom_id)->toBe($box->id)->and($unit->product->track_batches)->toBeTrue();
    $this->actingAs(withMfa($this->owner), 'web');

    $bought = ($this->doc)(['invoice_no' => 'PSI-8', 'settlement' => 'credit'], ['product_uom_id' => $unit->id, 'qty' => '1', 'unit_price' => '209.00', 'batch_no' => 'B835886',
        'expires_on' => now()->addYears(2)->toDateString(), 'sell_uom_id' => ProductUom::where('product_id', $unit->product_id)->where('is_base', true)->value('id'), 'new_price' => '10.00']);
    expect(DirectPurchases::margin($bought->lines()->with('unit', 'sellUnit')->first()))->toBe(65); // 60 tablets at 3.48, sold at 10
});
