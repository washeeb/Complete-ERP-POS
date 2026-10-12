<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Devices\DeviceService;
use App\Domain\Fashion\AlterationService;
use App\Domain\Fashion\ConsignmentService;
use App\Domain\Fashion\FashionReports;
use App\Domain\Fashion\StyleService;
use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\GlCheck;
use App\Domain\Inventory\InventoryService;
use App\Domain\Pos\ReturnService;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\StyleReport;
use App\Filament\Resources\Alterations\AlterationResource;
use App\Filament\Resources\Styles\StyleResource;
use App\Models\Alteration;
use App\Models\ConsignmentSale;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Style;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\TaxGroup;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Ean13;
use App\Support\Qty;
use Illuminate\Support\Str;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = $this->branches['HQ'];
    $this->wh = Warehouse::where('branch_id', $this->hq->id)->first();
    enableModule($this->owner, $this->hq, 'fashion');
    $this->o = actorFor($this->owner);
    $this->styles = app(StyleService::class);
    $this->style = $this->styles->save($this->o, new Style, ['code' => 'kente-dr', 'name' => 'Kente wrap dress', 'tax_group_id' => TaxGroup::where('code', 'STD')->value('id'),
        'season' => 'ss26', 'collection' => 'Heritage', 'sizes' => 'S, M, L', 'colours' => ['Black', 'Gold'], 'price' => '450.00']);
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $this->till = Till::where('branch_id', $this->hq->id)->first();
    [$this->device] = app(DeviceService::class)->register($this->o, ['branch_id' => $this->hq->id, 'till_id' => $this->till->id, 'name' => 'T1', 'kind' => 'pos']);
    $this->device->forceFill(['status' => 'active'])->save();
    $this->cashier = staff($this->owner, 'efua', 2, 'teller', ScopeType::Till, $this->till->id);
    $this->tailor = staff($this->owner, 'abla', 2, 'tailor', ScopeType::Branch, $this->hq->id);
    $this->shift = app(ShiftService::class)->open($this->device, $this->cashier, 0);
    $this->seq = 0;
});

function fashionSale($t, array $items, array $extra = []): Sale
{
    $lines = [];
    $engine = [];
    foreach ($items as $i => $it) {
        [$p, $qty] = $it;
        $unit = $p->units->first();
        $alteration = isset($it[2]['alteration_id']) ? Alteration::find($it[2]['alteration_id']) : null;
        $price = $alteration ? $alteration->charge_minor : app(PriceResolver::class)->resolve($t->retail, $p->id, $unit->id, Qty::toMilli($qty), now())->amount_minor;
        $engine[] = ['key' => (string) $i, 'product_id' => $alteration ? 'alteration:'.$alteration->id : $p->id, 'category_id' => null, 'qty_milli' => Qty::toMilli($qty),
            'unit_price_minor' => $price, 'tax' => $p->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all(), 'manual' => null];
        $lines[] = ['product_id' => $p->id, 'product_uom_id' => $unit->id, 'qty' => $qty, 'unit_price_minor' => $price] + ($it[2] ?? []);
    }
    $calc = PromotionEngine::compute(['lines' => $engine, 'inclusive' => true, 'coupon' => null, 'sale_discount_bp' => 0, 'promotions' => []]);
    foreach ($calc['lines'] as $i => $r) {
        $lines[$i]['line_total_minor'] = $r['total'];
    }

    return app(SaleService::class)->post($t->device, $t->cashier, ['id' => (string) Str::uuid7(), 'device_seq' => ++$t->seq, 'occurred_at' => now()->toIso8601String(),
        'price_list_id' => $t->retail->id, 'shift_id' => $t->shift->id, 'lines' => $lines, 'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $calc['total']],
        'payments' => [['method' => 'cash', 'amount_minor' => $calc['total'], 'tendered_minor' => $calc['total']]]] + $extra)['sale'];
}

it('generates one product per size and colour, with SKU, barcode and price, and keeps them in line with the style', function () {
    expect($this->styles->generate($this->o, $this->style))->toBe(6)
        ->and($this->styles->generate($this->o, $this->style->fresh()))->toBe(0); // nothing new the second time
    $m = Product::where('sku', 'KENTE-DR-BLACK-M')->sole();
    expect($m->name)->toBe('Kente wrap dress Black M')->and($m->style_id)->toBe($this->style->id)->and([$m->size, $m->colour])->toBe(['M', 'Black'])
        ->and(Ean13::valid($m->barcodes()->value('code')))->toBeTrue()->and(str_starts_with($m->barcodes()->value('code'), '20'))->toBeTrue()
        ->and(app(PriceResolver::class)->resolve($this->retail, $m->id, $m->units->first()->id, 1000, now())->amount_minor)->toBe(45000)
        ->and($this->style->season)->toBe('SS26');

    // A new size adds two products; a size with products cannot be dropped.
    $this->styles->save($this->o, $this->style->fresh(), ['sizes' => 'S, M, L, XL']);
    expect($this->styles->generate($this->o, $this->style->fresh()))->toBe(2)
        ->and(fn () => $this->styles->save($this->o, $this->style->fresh(), ['sizes' => 'S, M']))->toThrow(DomainException::class, 'cannot be removed');

    // One price for every size and colour.
    expect($this->styles->setPrice($this->o, $this->style->fresh(), '399.00'))->toBe(8)
        ->and(app(PriceResolver::class)->resolve($this->retail, $m->id, $m->units->first()->id, 1000, now())->amount_minor)->toBe(39900);
    expect(fn () => $this->styles->save(actorFor($this->cashier), new Style, ['code' => 'X', 'name' => 'X', 'sizes' => 'S', 'colours' => 'Red']))->toThrow(AccessDenied::class);
});

it('shows sell-through by style and the size × colour grid of stock and sales', function () {
    $this->styles->generate($this->o, $this->style);
    $inv = app(InventoryService::class);
    foreach (Product::where('style_id', $this->style->id)->get() as $p) {
        $inv->adjust($this->o, $this->wh, $p, $p->units->first(), '5', 'opening', '', null, '200.00');
    }
    $bm = Product::where('sku', 'KENTE-DR-BLACK-M')->sole();
    fashionSale($this, [[$bm, '2'], [Product::where('sku', 'KENTE-DR-GOLD-S')->sole(), '1']]);
    $today = now()->toDateString();
    $grid = FashionReports::matrix($this->style->fresh(), $today, $today);
    expect($grid['cells']['Black']['M'])->toMatchArray(['on_hand' => '3.000', 'sold' => '2.000'])->and($grid['sizes'])->toBe(['S', 'M', 'L']);
    [$row] = FashionReports::styles($today, $today, 'SS26');
    expect($row->sold)->toBe('3.000')->and($row->on_hand)->toBe('27.000')->and($row->sell_through_bp)->toBe(1000); // 3 of 30
});

it('sells consignment stock, records what the consignor is owed, takes returns back and settles with a supplier bill', function () {
    $consignor = app(SupplierService::class)->save($this->o, new Supplier, ['code' => 'ADJOA', 'name' => 'Adjoa Designs']);
    $bag = makeProduct($this->owner, 'BAG-1', 'Beaded bag', retail: '230.00');
    expect(fn () => app(CatalogService::class)->saveProduct($this->o, $bag, ['consignor_id' => $consignor->id, 'consignment_bp' => 0]))->toThrow(DomainException::class, 'Consignment');
    app(CatalogService::class)->saveProduct($this->o, $bag, ['consignor_id' => $consignor->id, 'consignment_bp' => 6000]);
    $bag = $bag->fresh();
    app(InventoryService::class)->adjust($this->o, $this->wh, $bag, $bag->units->first(), '4', 'adjustment', 'consignment_in', 'Adjoa delivery', '0');

    $sale = fashionSale($this, [[$bag, '2']]); // 460.00 incl. VAT
    $row = ConsignmentSale::sole();
    expect($row->consignor_id)->toBe($consignor->id)->and($row->share_minor)->toBe(intdiv($row->net_minor * 6000 + 5000, 10000));

    $ret = app(ReturnService::class);
    $line = collect($ret->returnable($sale->fresh('lines', 'payments'))['lines'])->first();
    $ret->post($this->device, $this->cashier, ['id' => (string) Str::uuid7(), 'device_seq' => ++$this->seq, 'occurred_at' => now()->toIso8601String(), 'shift_id' => $this->shift->id,
        'original_sale_id' => $sale->id, 'reason' => 'Changed mind', 'lines' => [['original_line_id' => $line['id'], 'qty' => '1']], 'refunds' => [['method' => 'cash', 'amount_minor' => 23000]]]);
    $owed = ConsignmentService::owed($this->hq->id)->sole();
    expect((int) $owed->owed_minor)->toBe((int) ConsignmentSale::sum('share_minor'))->and((string) $owed->qty)->toBe('1.000');

    $bill = app(ConsignmentService::class)->settle($this->o, $consignor, $this->hq, now()->toDateString(), 'ADJOA-OCT');
    expect($bill)->toBeInstanceOf(SupplierBill::class)->and($bill->total_minor)->toBe((int) $owed->owed_minor)
        ->and(ConsignmentSale::whereNull('supplier_bill_id')->count())->toBe(0)
        ->and(fn () => app(ConsignmentService::class)->settle($this->o, $consignor, $this->hq, now()->toDateString(), 'AGAIN'))->toThrow(DomainException::class, 'Nothing is owed');
    app(AutoPoster::class)->run($this->tenant->id);
    expect(app(GlCheck::class)->run($this->tenant->id)['ok'])->toBeTrue();
});

it('takes alterations in, charges them at the till at the agreed price, and hands free ones back', function () {
    $alt = makeProduct($this->owner, 'ALTERATION', 'Alteration', retail: '0.00');
    app(CatalogService::class)->saveProduct($this->o, $alt, ['track_stock' => false]);
    $alt = $alt->fresh();
    $svc = app(AlterationService::class);
    $t = actorFor($this->tailor);
    $a = $svc->open($t, ['branch_id' => $this->hq->id, 'customer_name' => 'Ama', 'phone' => '024 111 2222', 'garment' => 'Trousers', 'work' => 'Hem 2 cm', 'charge_minor' => 5000,
        'due_on' => now()->addDays(2)->toDateString()]);
    expect($a->number)->toStartWith('ALT-')->and($a->phone)->toBe('0241112222');
    $svc->setStatus($t, $a, 'in_progress', 'Abla');
    $a = $svc->setStatus($t, $a->fresh(), 'ready');
    expect(fn () => $svc->handOver($t, $a))->toThrow(DomainException::class, 'take payment')
        ->and(fn () => fashionSale($this, [[makeProduct($this->owner, 'OTHER', 'Other', retail: '10.00'), '1', ['alteration_id' => $a->id]]]))->toThrow(DomainException::class);

    $sale = fashionSale($this, [[$alt, '1', ['alteration_id' => $a->id]]]);
    expect($sale->total_minor)->toBe(5000)->and($a->fresh()->status)->toBe('collected')->and($a->fresh()->sale_id)->toBe($sale->id)
        ->and($sale->lines->first()->alteration_id)->toBe($a->id);

    $free = $svc->open($t, ['branch_id' => $this->hq->id, 'customer_name' => 'Kofi', 'garment' => 'Shirt', 'work' => 'Sleeves shortened', 'charge_minor' => 0]);
    $svc->setStatus($t, $free, 'ready');
    expect($svc->handOver($t, $free->fresh())->status)->toBe('collected')
        ->and(fn () => $svc->open(actorFor($this->cashier), ['branch_id' => $this->hq->id, 'customer_name' => 'X', 'garment' => 'Y', 'work' => 'Z']))->toThrow(AccessDenied::class);
});

it('shows the fashion screens', function () {
    $this->styles->generate($this->o, $this->style);
    $a = app(AlterationService::class)->open(actorFor($this->tailor), ['branch_id' => $this->hq->id, 'customer_name' => 'Ama', 'garment' => 'Dress', 'work' => 'Take in waist', 'charge_minor' => 0]);
    $this->actingAs(withMfa($this->owner));
    foreach ([StyleResource::getUrl('index'), StyleResource::getUrl('view', ['record' => $this->style]), AlterationResource::getUrl('index'), StyleReport::getUrl()] as $url) {
        $this->get($url)->assertOk();
    }
    $this->get(StyleResource::getUrl('view', ['record' => $this->style]))->assertSee('KENTE-DR-GOLD-L');
    $this->get(AlterationResource::getUrl('index'))->assertSee('Take in waist');
    $this->get(StyleReport::getUrl())->assertSee('Kente wrap dress');
});
