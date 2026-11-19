<?php

use App\Domain\Inventory\InventoryService;
use App\Models\SavedReport;
use App\Models\Warehouse;
use App\Security\ScopeType;

// Report builder and saved reports in the new back office (release 7, part 3): run a report, open the rows behind a
// line, export it, save, share and schedule it, and delete it — through ReportEngine and ReportService.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $wh = Warehouse::where('branch_id', $this->b['HQ']->id)->first();
    $milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    app(InventoryService::class)->adjust(actorFor($this->owner), $wh, $milo, $milo->units->first(), '20', 'opening', '', null, '40.00');
    $this->fm = withMfa(staff($this->owner, 'kwame', 5, 'finance_manager'));
    $this->stock = ['dataset' => 'stock_levels', 'dimensions' => ['product'], 'measures' => ['on_hand', 'value'], 'period' => 'this_month', 'direction' => 'desc'];
});

it('runs a report, opens the rows behind a line, and exports it', function () {
    $this->actingAs($this->fm, 'web');
    $o = $this->getJson('/office-api/report-builder/options')->assertOk()->json();
    expect(array_column($o['datasets'], 'id'))->toContain('stock_levels', 'journal_lines')
        ->and(collect($o['datasets'])->firstWhere('id', 'stock_levels')['dated'])->toBeFalse()->and($o['schedules'])->not->toBeEmpty();

    $r = $this->postJson('/office-api/report-builder/run', $this->stock)->assertOk()->json();
    expect($r['table']['columns'][1])->toBe(['label' => 'On hand', 'end' => true])->and($r['hint'])->toContain('Click a line');
    $line = collect($r['table']['rows'])->first(fn ($x) => str_contains($x['cells'][0], 'MILO'));
    expect($line['cells'][1])->toBe('20')->and($line['drill'])->toBe(['product' => 'MILO MILO 400G'])
        ->and(end($r['table']['rows'])['kind'])->toBe('total');

    $detail = $this->postJson('/office-api/report-builder/run', $this->stock + ['drill' => $line['drill']])->assertOk()->json();
    expect($detail['hint'])->toContain('Rows behind')->and($detail['table']['rows'])->not->toBeEmpty()->and($detail['table']['rows'][0]['drill'])->toBeNull();

    $csv = $this->get('/office-api/report-builder/export?format=csv&choices='.urlencode(json_encode($this->stock)));
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('On hand')->toContain('MILO');
    $this->postJson('/office-api/report-builder/run', ['dataset' => 'stock_levels', 'dimensions' => [], 'measures' => []])->assertStatus(422);
});

it('saves, shares, schedules and deletes a report; only who saved it may change it', function () {
    $this->actingAs($this->fm, 'web');
    $this->postJson('/office-api/saved-reports', ['name' => 'Stock by product', 'schedule' => 'weekly', 'schedule_day' => 1, 'recipients' => [], 'choices' => $this->stock])
        ->assertStatus(422)->assertJsonPath('detail', 'A scheduled report needs at least one recipient.');
    $saved = $this->postJson('/office-api/saved-reports', ['name' => 'Stock by product', 'shared' => true, 'schedule' => 'weekly', 'schedule_day' => 1, 'schedule_time' => '07:00',
        'format' => 'xlsx', 'recipients' => [['type' => 'user', 'to' => $this->owner->id]], 'choices' => $this->stock])->assertOk()->json('report');
    expect($saved)->toMatchArray(['name' => 'Stock by product', 'mine' => true, 'shared' => true, 'schedule' => 'weekly', 'format' => 'xlsx'])->and($saved['next'])->not->toBeNull()
        ->and($saved['open'])->toBe("/office/report-builder?report={$saved['id']}");

    $open = $this->getJson("/office-api/saved-reports/{$saved['id']}")->assertOk()->json();
    expect($open['choices'])->toMatchArray(['dataset' => 'stock_levels', 'dimensions' => ['product'], 'measures' => ['on_hand', 'value']]);
    expect($this->getJson('/office-api/saved-reports?q=stock')->json('rows.0.name'))->toBe('Stock by product');
    $this->postJson("/office-api/saved-reports/{$saved['id']}/send")->assertOk();

    $gm = withMfa(staff($this->owner, 'yaw', 5, 'general_manager'));
    $this->actingAs($gm, 'web');
    expect($this->getJson('/office-api/saved-reports')->json('rows.0'))->toMatchArray(['name' => 'Stock by product', 'mine' => false]); // shared
    $this->postJson('/office-api/saved-reports', ['id' => $saved['id'], 'name' => 'Mine now', 'choices' => $this->stock])->assertForbidden();
    $this->deleteJson("/office-api/saved-reports/{$saved['id']}")->assertForbidden();

    $this->actingAs($this->fm, 'web')->deleteJson("/office-api/saved-reports/{$saved['id']}")->assertOk();
    expect(SavedReport::count())->toBe(0);

    $teller = staff($this->owner, 'efua', 2, 'teller', ScopeType::Branch, $this->b['HQ']->id);
    $this->actingAs($teller, 'web')->getJson('/office-api/report-builder/options')->assertForbidden();
});
