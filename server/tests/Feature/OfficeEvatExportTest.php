<?php

use App\Domain\Devices\DeviceService;
use App\Domain\Finance\AutoPoster;
use App\Domain\Fiscal\FiscalAdapter;
use App\Domain\Fiscal\FiscalService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Support\DomainException;
use App\Models\FiscalSubmission;
use App\Models\PriceList;
use App\Models\Sale;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Support\Str;

// GRA E-VAT and Export to QuickBooks / Xero / Sage in the new back office (release 8, part 5).

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->hq = $this->b['HQ'];
    $this->o = actorFor($this->owner);
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    app(InventoryService::class)->adjust($this->o, Warehouse::where('branch_id', $this->hq->id)->first(), $this->milo, $this->milo->units->first(), '50', 'opening', '', null, '40.00');
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $till = Till::where('branch_id', $this->hq->id)->first();
    [$this->device] = app(DeviceService::class)->register($this->o, ['branch_id' => $this->hq->id, 'till_id' => $till->id, 'name' => 'T1', 'kind' => 'pos']);
    $this->device->forceFill(['status' => 'active'])->save();
    $this->cashier = staff($this->owner, 'efua', 2, 'teller', ScopeType::Till, $till->id);
    $this->shift = app(ShiftService::class)->open($this->device, $this->cashier, 0);
    $this->seq = 0;
    $this->accountant = withMfa(staff($this->owner, 'esi', 4, 'accountant'));
    $this->gra = new class implements FiscalAdapter
    {
        public bool $down = false;

        public function submit(array $document): array
        {
            if ($this->down) {
                throw new DomainException('GRA E-VAT not reachable', 'fiscal_unreachable');
            }

            return ['reference' => 'GRA-'.$document['number'], 'qr' => null, 'raw' => ['ok' => true]];
        }

        public function submitBatch(array $documents): array
        {
            return array_map(fn ($d) => ['ok' => true, 'reference' => 'B-'.$d['number'], 'qr' => null, 'error' => null, 'raw' => []], $documents);
        }
    };
    app()->instance('fiscal.fake', $this->gra);
});

function evatSale($t): Sale
{
    $unit = $t->milo->units->first();
    $price = app(PriceResolver::class)->resolve($t->retail, $t->milo->id, $unit->id, Qty::toMilli('1'), now())->amount_minor;
    $calc = PromotionEngine::compute(['lines' => [['key' => '0', 'product_id' => $t->milo->id, 'category_id' => null, 'qty_milli' => Qty::toMilli('1'), 'unit_price_minor' => $price,
        'tax' => $t->milo->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all(), 'manual' => null]],
        'inclusive' => true, 'coupon' => null, 'sale_discount_bp' => 0, 'promotions' => []]);

    return app(SaleService::class)->post($t->device, $t->cashier, ['id' => (string) Str::uuid7(), 'device_seq' => ++$t->seq, 'occurred_at' => now()->toIso8601String(),
        'price_list_id' => $t->retail->id, 'shift_id' => $t->shift->id, 'lines' => [['product_id' => $t->milo->id, 'product_uom_id' => $unit->id, 'qty' => '1', 'unit_price_minor' => $price,
            'line_total_minor' => $calc['lines'][0]['total']]], 'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $calc['total']],
        'payments' => [['method' => 'cash', 'amount_minor' => $calc['total'], 'tendered_minor' => $calc['total']]]])['sale'];
}

it('lists what was reported to GRA, shows one, sends a waiting one again, and sends the daily batch', function () {
    $settings = app(Settings::class);
    $settings->set($this->o, 'evat.mode', 'realtime');
    $this->gra->down = true;
    $waiting = app(FiscalService::class)->submitSaleNow($sale = evatSale($this));
    expect($waiting->status)->toBe('pending');

    $this->actingAs($this->accountant, 'web');
    $o = $this->getJson('/office-api/gra-evat/options')->assertOk()->json();
    expect($o)->toMatchArray(['mode' => 'realtime', 'can_batch' => false]);
    $l = $this->getJson('/office-api/gra-evat?status=pending')->assertOk()->json();
    expect($l['total'])->toBe(1)->and($l['counts']['pending'])->toBe(1)->and($l['rows'][0])->toMatchArray(['number' => $sale->receipt_no, 'type' => 'sale', 'can_retry' => true]);
    $one = $this->getJson("/office-api/gra-evat/{$waiting->id}")->assertOk()->json('report');
    expect($one['sent'])->toContain($sale->receipt_no)->and($one['error'])->toContain('not reachable');

    $this->gra->down = false;
    $r = $this->postJson("/office-api/gra-evat/{$waiting->id}/send")->assertOk()->json();
    expect($r['report'])->toMatchArray(['status' => 'submitted', 'reference' => "GRA-{$sale->receipt_no}", 'can_retry' => false])->and($r['message'])->toBe('Accepted by GRA.');
    $this->postJson("/office-api/gra-evat/{$waiting->id}/send")->assertStatus(422); // already accepted
    $this->postJson('/office-api/gra-evat/send-waiting')->assertStatus(422); // not the daily batch

    $settings->set($this->o, 'evat.mode', 'daily');
    $s2 = evatSale($this);
    app(FiscalService::class)->recordSale($s2);
    expect($this->getJson('/office-api/gra-evat/options')->json('can_batch'))->toBeTrue();
    expect($this->postJson('/office-api/gra-evat/send-waiting')->assertOk()->json('sent'))->toBe(1);
    expect(FiscalSubmission::where('doc_id', $s2->id)->value('gra_reference'))->toBe("B-{$s2->receipt_no}")
        ->and($this->getJson("/office-api/gra-evat?q={$s2->receipt_no}")->json('total'))->toBe(1);

    // A teller sees none of it; an accountant for another branch sees no HQ reports.
    $this->actingAs($this->cashier, 'web')->getJson('/office-api/gra-evat')->assertForbidden();
    $other = withMfa(staff($this->owner, 'kofi', 4, 'accountant', ScopeType::Branch, collect($this->b)->first(fn ($b) => $b->id !== $this->hq->id)->id));
    $this->actingAs($other, 'web');
    expect($this->getJson('/office-api/gra-evat')->assertOk()->json('total'))->toBe(0);
    $this->getJson("/office-api/gra-evat/{$waiting->id}")->assertNotFound();
    $this->postJson("/office-api/gra-evat/{$waiting->id}/send")->assertNotFound();
});

it('downloads the journals for QuickBooks, Xero and Sage for company-wide finance only', function () {
    evatSale($this);
    app(AutoPoster::class)->run($this->tenant->id);
    $today = now()->toDateString();

    $this->actingAs($this->accountant, 'web');
    $o = $this->getJson('/office-api/accounting-export')->assertOk()->json();
    expect(array_keys($o['formats']))->toBe(['quickbooks', 'xero', 'sage'])->and($o['from'])->toBe(now()->subMonthNoOverflow()->startOfMonth()->toDateString());
    $qb = $this->get("/office-api/accounting-export/file?format=quickbooks&from={$today}&to={$today}");
    $qb->assertOk();
    expect($qb->streamedContent())->toContain('Journal No')->toContain('Debits');
    $this->getJson("/office-api/accounting-export/file?format=lotus&from={$today}&to={$today}")->assertStatus(422);
    $this->getJson('/office-api/accounting-export/file?format=xero&from=2001-01-01&to=2001-01-31')->assertStatus(422)->assertJsonPath('detail', fn ($d) => str_contains($d, 'No journal entries'));
    $this->getJson("/office-api/accounting-export/file?format=sage&from={$today}&to=2001-01-01")->assertStatus(422);

    $branchOnly = withMfa(staff($this->owner, 'adwoa', 4, 'accountant', ScopeType::Branch, $this->hq->id));
    $this->actingAs($branchOnly, 'web')->getJson('/office-api/accounting-export')->assertForbidden();
    $this->get("/office-api/accounting-export/file?format=xero&from={$today}&to={$today}")->assertForbidden();
});
