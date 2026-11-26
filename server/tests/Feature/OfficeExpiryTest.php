<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Purchasing\SupplierService;
use App\Models\Markdown;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Expiry & returns and the industries' near-expiry markdowns in the new back office (part 13).

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = $this->branches['HQ'];
    $this->wh = Warehouse::where('branch_id', $this->hq->id)->first();
    $o = actorFor($this->owner);
    $catalog = app(CatalogService::class);
    $this->amox = makeProduct($this->owner, 'AMOX', 'Amoxicillin 500mg', retail: '2.00');
    $catalog->saveProduct($o, $this->amox, ['track_batches' => true]);
    $this->amox = $this->amox->fresh();
    $this->peth = makeProduct($this->owner, 'PETH', 'Pethidine 50mg', retail: '20.00');
    $catalog->saveProduct($o, $this->peth, ['track_batches' => true, 'drug_schedule' => 'controlled']);
    $this->peth = $this->peth->fresh();
    $inv = app(InventoryService::class);
    $inv->adjust($o, $this->wh, $this->amox, $this->amox->units->first(), '10', 'opening', '', null, '1.00', 'A-OLD', now()->addDays(3)->toDateString());
    $inv->adjust($o, $this->wh, $this->amox, $this->amox->units->first(), '20', 'opening', '', null, '1.00', 'A-SOON', now()->addDays(25)->toDateString());
    $inv->adjust($o, $this->wh, $this->peth, $this->peth->units->first(), '5', 'opening', '', null, '10.00', 'P-1', now()->addDays(3)->toDateString());
    $this->supplier = app(SupplierService::class)->save($o, new Supplier, ['code' => 'SUPP', 'name' => 'Test Supplier', 'return_days_before_expiry' => 0]);
    withMfa($this->owner);
    Carbon::setTestNow(now()->addDays(5)); // A-OLD and P-1 have now expired; A-SOON expires in 20 days
});

afterEach(fn () => Carbon::setTestNow());

function officeExpKey($t, string $batchNo): string
{
    return $t->wh->id.'|'.DB::table('stock_batches')->where('batch_no', $batchNo)->value('id');
}

it('lists expiring batches and disposes, returns and marks them down from the ticks', function () {
    Storage::fake('local');
    app(Settings::class)->set(actorFor($this->owner), 'stock.disposal_evidence', 'method_witness_certificate');
    $this->actingAs($this->owner, 'web');

    $o = $this->getJson('/office-api/expiry/options')->assertOk()->json();
    expect($o['evidence'])->toBe('method_witness_certificate')->and(collect($o['warehouses'])->pluck('id')->all())->toContain($this->wh->id);
    $list = $this->getJson('/office-api/expiry?status=30')->assertOk()->json();
    expect(collect($list['rows'])->pluck('batch_no')->sort()->values()->all())->toBe(['A-OLD', 'A-SOON', 'P-1'])
        ->and(collect($list['rows'])->firstWhere('batch_no', 'P-1'))->toMatchArray(['controlled' => true, 'days_left' => -2, 'value' => 5000]);
    expect($this->getJson('/office-api/expiry?status=expired&controlled=1')->json('total'))->toBe(1)
        ->and($this->getJson('/office-api/expiry?status=30&q=a-soon')->json('total'))->toBe(1);

    // Dispose: the certificate is required by the setting, then sent as a file.
    $base = ['keys' => [officeExpKey($this, 'A-OLD')], 'reason' => 'expired', 'method' => 'incineration', 'disposed_on' => now()->toDateString(), 'witness' => 'Test Witness'];
    $this->post('/office-api/expiry/dispose', $base, ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('detail', fn ($d) => str_contains($d, 'certificate'));
    $open = $this->post('/office-api/expiry/dispose', $base + ['certificate' => UploadedFile::fake()->create('cert.pdf', 40, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertOk()->json('open');
    $a = StockAdjustment::where('kind', 'disposal')->sole();
    expect($open)->toBe("/office/adjustments/{$a->id}")->and($a->status)->toBe('submitted')->and($a->witness_name)->toBe('Test Witness');
    Storage::disk('local')->assertExists($a->certificate_path);

    // Return to the supplier; ticks from two stores are refused by the service.
    $this->postJson('/office-api/expiry/return', ['keys' => [officeExpKey($this, 'P-1')], 'supplier' => $this->supplier->id, 'settlement' => 'none', 'reason' => 'expired'])
        ->assertOk()->assertJsonPath('open', fn ($u) => str_contains($u, '/office/supplier-returns/'));
    expect(SupplierReturn::sole()->settlement)->toBe('none');

    // Mark down: what has already expired is skipped.
    $this->postJson('/office-api/expiry/markdown', ['keys' => [officeExpKey($this, 'A-OLD'), officeExpKey($this, 'A-SOON')], 'percent' => 30])
        ->assertOk()->assertJsonPath('message', '1 batch(es) marked down. Print the stickers from Batches & expiry.');
    expect(Markdown::count())->toBe(1);

    $url = $this->getJson("/office-api/expiry/register?warehouse={$this->wh->id}&from=".now()->startOfMonth()->toDateString().'&to='.now()->toDateString())->assertOk()->json('url');
    $this->get($url)->assertOk()->assertSee('DISPOSAL');

    $this->flushSession();
    $this->actingAs(withMfa(staff($this->owner, 'esi', 2, 'teller', ScopeType::Branch, $this->hq->id)), 'web');
    $this->getJson('/office-api/expiry')->assertForbidden();
    $this->postJson('/office-api/expiry/markdown', ['keys' => [officeExpKey($this, 'A-SOON')], 'percent' => 30])->assertForbidden();
});

it('shows an industry\'s near-expiry markdowns for its own stores, without the full batch list', function () {
    enableModule($this->owner, $this->hq, 'pharmacy');
    $this->actingAs($this->owner, 'web');

    $all = $this->getJson("/office-api/batches?warehouse={$this->wh->id}")->assertOk()->json();
    expect($all['kind'])->toBeNull()->and($all['batches'])->not->toBeEmpty();
    $rx = $this->getJson("/office-api/batches?warehouse={$this->wh->id}&industry=pharmacy")->assertOk()->json();
    expect($rx['kind'])->toBe('pharmacy')->and($rx['batches'])->toBe([])->and(collect($rx['warehouses'])->pluck('id')->all())->toBe([$this->wh->id])
        ->and(collect($rx['expired'])->pluck('batch_no')->sort()->values()->all())->toBe(['A-OLD', 'P-1']);
    $batch = DB::table('stock_batches')->where('batch_no', 'A-SOON')->value('id');
    $this->postJson('/office-api/batches/markdowns?industry=pharmacy', ['warehouse' => $this->wh->id, 'batch' => $batch, 'percent' => '25'])->assertOk();
    expect(Markdown::sole()->percent_bp)->toBe(2500);

    $grocery = $this->getJson("/office-api/batches?warehouse={$this->wh->id}&industry=grocery")->json(); // the pharmacy's store is not offered there
    expect(collect($grocery['warehouses'] ?? [])->pluck('id')->all())->not->toContain($this->wh->id)->and($grocery['warehouse'] ?? '')->not->toBe($this->wh->id);
    $this->getJson("/office-api/batches?warehouse={$this->wh->id}&industry=bakery")->assertOk()->assertJsonPath('kind', null); // unknown: Batches & expiry
});
