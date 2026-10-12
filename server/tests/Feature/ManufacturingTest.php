<?php

use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\GlCheck;
use App\Domain\Inventory\InventoryService;
use App\Domain\Manufacturing\BomService;
use App\Domain\Manufacturing\MrpPlanner;
use App\Domain\Manufacturing\WorkOrderService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ProductionPlanning;
use App\Filament\Resources\Boms\BomResource;
use App\Filament\Resources\WorkCentres\WorkCentreResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Bom;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\Warehouse;
use App\Models\WorkCentre;
use App\Models\WorkOrder;
use App\Security\ScopeType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = $this->branches['HQ'];
    enableModule($this->owner, $this->hq, 'manufacturing');
    $this->o = actorFor($this->owner);
    $this->wh = Warehouse::where('branch_id', $this->hq->id)->first();
    $this->operator = staff($this->owner, 'esi', 2, 'machine_operator', ScopeType::Branch, $this->hq->id);
    $this->inspector = staff($this->owner, 'kofi', 3, 'qc_inspector', ScopeType::Branch, $this->hq->id);

    $this->bread = makeProduct($this->owner, 'BREAD', 'Sliced bread', retail: '20.00');
    $this->dough = makeProduct($this->owner, 'DOUGH', 'Bread dough', retail: '1.00');
    $this->flour = makeProduct($this->owner, 'FLOUR', 'Flour (kg)', retail: '1.00');
    $this->yeast = makeProduct($this->owner, 'YEAST', 'Yeast (sachet)', retail: '1.00');
    $this->bran = makeProduct($this->owner, 'BRAN', 'Bran (kg)', retail: '1.00');
    $inv = app(InventoryService::class);
    $inv->adjust($this->o, $this->wh, $this->flour, $this->flour->units->first(), '100', 'opening', '', null, '5.00');
    $inv->adjust($this->o, $this->wh, $this->yeast, $this->yeast->units->first(), '50', 'opening', '', null, '2.00');
    $inv->adjust($this->o, $this->wh, $this->bran, $this->bran->units->first(), '1', 'opening', '', null, '1.00');

    $boms = app(BomService::class);
    $this->mix = $boms->saveWorkCentre($this->o, new WorkCentre, ['code' => 'mix', 'name' => 'Mixer', 'branch_id' => $this->hq->id, 'hourly_rate' => '60.00', 'hours_per_day' => '8']);
    $this->oven = $boms->saveWorkCentre($this->o, new WorkCentre, ['code' => 'OVEN', 'name' => 'Oven', 'branch_id' => $this->hq->id, 'hourly_rate' => '120.00', 'hours_per_day' => '10']);
    $u = fn (Product $p) => $p->units->first()->id;
    // 10 dough from 5 flour and 1 yeast, 30 minutes on the mixer.
    $this->doughBom = $boms->activate($this->o, $boms->save($this->o, new Bom, ['code' => 'DOUGH', 'product_id' => $this->dough->id, 'output_qty' => '10',
        'lines' => [['product_uom_id' => $u($this->flour), 'qty' => '5'], ['product_uom_id' => $u($this->yeast), 'qty' => '1']],
        'operations' => [['name' => 'Mix', 'work_centre_id' => $this->mix->id, 'setup_minutes' => 0, 'run_minutes' => 30]]]));
    // 10 loaves from 10 dough, giving 1 kg bran; baked (backflushed) then packed (time recorded); checked before stock.
    $this->breadBom = $boms->activate($this->o, $boms->save($this->o, new Bom, ['code' => 'BREAD', 'product_id' => $this->bread->id, 'output_qty' => '10', 'qc_required' => true,
        'lines' => [['product_uom_id' => $u($this->dough), 'qty' => '10'], ['kind' => 'by_product', 'product_uom_id' => $u($this->bran), 'qty' => '1']],
        'operations' => [['name' => 'Bake', 'work_centre_id' => $this->oven->id, 'setup_minutes' => 10, 'run_minutes' => 20],
            ['name' => 'Pack', 'work_centre_id' => $this->mix->id, 'setup_minutes' => 0, 'run_minutes' => 15, 'backflush' => false]]]));
});

function mfgQty(Warehouse $wh, Product $p): string
{
    return (string) (StockLevel::where('warehouse_id', $wh->id)->where('product_id', $p->id)->value('on_hand') ?? '0.000');
}

function glKey(string $key): int
{
    return (int) DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')->where('accounts.system_key', $key)->sum('journal_lines.amount_minor');
}

it('keeps bills of materials safe: no loops, in-use BOMs never change, revisions replace them', function () {
    $boms = app(BomService::class);
    expect($this->doughBom->status)->toBe('active')->and($this->mix->code)->toBe('MIX')->and($this->mix->hourly_rate_minor)->toBe(6000)
        ->and(fn () => $boms->save($this->o, $this->doughBom, ['output_qty' => '20']))->toThrow(DomainException::class, 'new revision')
        ->and(fn () => $boms->save($this->o, new Bom, ['code' => 'LOOP', 'product_id' => $this->dough->id, 'lines' => [['product_uom_id' => $this->bread->units->first()->id, 'qty' => '1']]]))
        ->toThrow(DomainException::class, 'made from itself')
        ->and(fn () => $boms->saveWorkCentre(actorFor($this->operator), new WorkCentre, ['code' => 'X', 'name' => 'X', 'branch_id' => $this->hq->id]))->toThrow(AccessDenied::class);

    $rev = $boms->revise($this->o, $this->doughBom);
    expect($rev->revision)->toBe(2)->and($rev->status)->toBe('draft')->and($rev->lines)->toHaveCount(2);
    $rev = $boms->save($this->o, $rev, ['output_qty' => '20']);
    $boms->activate($this->o, $rev);
    expect($this->doughBom->fresh()->status)->toBe('archived')->and(BomService::activeFor($this->dough->id)->id)->toBe($rev->id);

    // Estimated cost per dough: (5 × 5.00 + 1 × 2.00) / 20 materials + 30 min × 60.00/h / 20.
    expect($boms->estimate($rev, $this->wh->id))->toMatchArray(['material' => 135, 'labour' => 150, 'per_unit' => 285]);
});

it('makes a sub-assembly and a finished product at actual cost, with backflush, by-products, scrap and a quality check', function () {
    $wos = app(WorkOrderService::class);
    $op = actorFor($this->operator);

    // 20 dough: 10 flour + 2 yeast (GHS 54.00) and an hour on the mixer (GHS 60.00) → GHS 5.70 each.
    $dough = $wos->release($op, $wos->create($op, ['warehouse_id' => $this->wh->id, 'product_id' => $this->dough->id, 'qty' => '20']));
    expect($dough->number)->toStartWith('WO')->and($dough->materials)->toHaveCount(2);
    $dough = $wos->finish($op, $wos->reportOutput($op, $dough, '20'));
    expect($dough->status)->toBe('done')->and($dough->material_minor)->toBe(5400)->and($dough->labour_minor)->toBe(6000)
        ->and($dough->output_minor)->toBe(11400)->and($dough->variance_minor)->toBe(0)->and(mfgQty($this->wh, $this->dough))->toBe('20.000')
        ->and(mfgQty($this->wh, $this->flour))->toBe('90.000');

    // 20 loaves: 18 good, 2 scrapped; output waits for the quality check.
    $bread = $wos->release($op, $wos->create($op, ['warehouse_id' => $this->wh->id, 'product_id' => $this->bread->id, 'qty' => '20', 'due_on' => now()->addDay()->toDateString()]));
    $bread = $wos->reportOutput($op, $bread, '18', '2');
    expect(mfgQty($this->wh, $this->dough))->toBe('0.000')->and(mfgQty($this->wh, $this->bread))->toBe('0.000')
        ->and((string) $bread->qty_awaiting_qc)->toBe('18.000')->and($bread->material_minor)->toBe(11400)
        ->and($bread->labour_minor)->toBe(10000) // bake: 10 min set-up + 2 min × 20 = 50 min at 120.00/h
        ->and(fn () => $wos->reportOutput($op, $bread, '1'))->toThrow(DomainException::class, 'Not enough');
    $bread = $wos->recordTime($op, $bread, 20, '30'); // packing at the mixer's rate
    expect($bread->labour_minor)->toBe(13000)
        ->and(fn () => $wos->inspect($op, $bread, '18', '0'))->toThrow(AccessDenied::class)
        ->and(fn () => $wos->inspect(actorFor($this->inspector), $bread, '17', '1'))->toThrow(DomainException::class, 'why')
        ->and(fn () => $wos->finish($op, $bread))->toThrow(DomainException::class, 'quality check');

    $qc = $wos->inspect(actorFor($this->inspector), $bread, '17', '1', [['check' => 'Weight 400 g', 'result' => 'pass', 'value' => '402 g']], 'One loaf burnt');
    $bread = $bread->fresh('materials');
    // Cost so far 24,400 less bran 1.7 kg × 1.00 = 24,230 over 17 good loaves → 14.25 each; the 5 left goes to differences.
    expect($qc->number)->toStartWith('QC')->and((string) $bread->qty_done)->toBe('17.000')->and((string) $bread->qty_scrapped)->toBe('3.000')
        ->and($bread->by_product_minor)->toBe(170)->and($bread->output_minor)->toBe(17 * 1425)
        ->and(mfgQty($this->wh, $this->bread))->toBe('17.000')->and(mfgQty($this->wh, $this->bran))->toBe('2.700');
    $bread = $wos->finish($op, $bread);
    expect($bread->variance_minor)->toBe(5)->and($bread->wipMinor())->toBe(0);

    app(AutoPoster::class)->run($this->tenant->id);
    expect(glKey('wip'))->toBe(0)->and(glKey('production_absorbed'))->toBe(-19000)->and(glKey('production_variance'))->toBe(5)
        ->and(app(GlCheck::class)->run($this->tenant->id)['ok'])->toBeTrue();
});

it('issues and returns materials by hand, finishes short only with a reason, and cancels only untouched orders', function () {
    $wos = app(WorkOrderService::class);
    $op = actorFor($this->operator);
    $wo = $wos->release($op, $wos->create($op, ['warehouse_id' => $this->wh->id, 'product_id' => $this->dough->id, 'qty' => '10']));
    expect(fn () => $wos->issue($op, $wo, [(string) Str::uuid7() => '1']))->toThrow(DomainException::class, 'not on this work order');
    $flour = $wo->materials->firstWhere('product_id', $this->flour->id);
    $wo = $wos->issue($op, $wo, [$flour->id => '8']);
    expect($wo->status)->toBe('in_progress')->and($wo->material_minor)->toBe(4000)->and(mfgQty($this->wh, $this->flour))->toBe('92.000')
        ->and(fn () => $wos->cancel($op, $wo, 'Not needed'))->toThrow(DomainException::class, 'started')
        ->and(fn () => $wos->returnMaterials($op, $wo, [$flour->id => '9']))->toThrow(DomainException::class, 'only 8');
    $wo = $wos->returnMaterials($op, $wo, [$flour->id => '8']);
    expect($wo->material_minor)->toBe(0)->and(mfgQty($this->wh, $this->flour))->toBe('100.000')
        ->and(fn () => $wos->finish($op, $wo))->toThrow(DomainException::class, 'reason');
    $wo = $wos->finish($op, $wo, 'Order cancelled by the customer');
    expect($wo->status)->toBe('done')->and($wo->variance_minor)->toBe(0);

    $draft = $wos->create($op, ['warehouse_id' => $this->wh->id, 'product_id' => $this->bread->id, 'qty' => '5']);
    expect($wos->cancel($op, $draft, 'Raised by mistake')->status)->toBe('cancelled');

    // Releasing with sub-assemblies raises a draft order for the dough the warehouse is short of.
    $parent = $wos->release($op, $wos->create($op, ['warehouse_id' => $this->wh->id, 'product_id' => $this->bread->id, 'qty' => '30']), subAssemblies: true);
    $child = WorkOrder::where('parent_work_order_id', $parent->id)->sole();
    expect($child->product_id)->toBe($this->dough->id)->and((string) $child->qty_planned)->toBe('30.000')->and($child->status)->toBe('draft');
});

it('suggests what to make and what to buy, level by level, and shows the work centre load', function () {
    DB::table('reorder_rules')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'warehouse_id' => $this->wh->id, 'product_id' => $this->bread->id,
        'min_qty' => 100, 'max_qty' => 300, 'origin_node' => 'test', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $plan = app(MrpPlanner::class)->plan($this->wh->id);
    expect(collect($plan['make'])->pluck('suggested', 'sku')->all())->toBe(['BREAD' => 300.0, 'DOUGH' => 300.0])
        ->and(collect($plan['buy'])->pluck('suggested', 'sku')->all())->toBe(['FLOUR' => 50.0])
        ->and($plan['load'])->toBe([]);

    app(WorkOrderService::class)->create(actorFor($this->operator), ['warehouse_id' => $this->wh->id, 'product_id' => $this->bread->id, 'qty' => '300']);
    $plan = app(MrpPlanner::class)->plan($this->wh->id);
    expect(collect($plan['make'])->pluck('suggested', 'sku')->all())->toBe(['DOUGH' => 300.0])
        ->and(collect($plan['load'])->firstWhere('code', 'OVEN')['hours'])->toBe(10.2); // 10 min + 20 min × 30 batches
});

it('shows the manufacturing screens', function () {
    $this->actingAs(withMfa($this->owner));
    $wo = app(WorkOrderService::class)->create($this->o, ['warehouse_id' => $this->wh->id, 'product_id' => $this->bread->id, 'qty' => '10']);
    foreach ([WorkCentreResource::getUrl(), BomResource::getUrl(), BomResource::getUrl('view', ['record' => $this->breadBom]), WorkOrderResource::getUrl(),
        WorkOrderResource::getUrl('view', ['record' => $wo]), ProductionPlanning::getUrl()] as $url) {
        $this->get($url)->assertOk();
    }
    $this->flushSession();
    $this->actingAs(withMfa($this->inspector))->get(BomResource::getUrl())->assertOk();
    $this->flushSession();
    $this->actingAs(withMfa(staff($this->owner, 'ama', 2, 'teller')))->get(WorkOrderResource::getUrl())->assertForbidden();
});
