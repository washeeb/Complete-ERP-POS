<?php

use App\Domain\Inventory\InventoryService;
use App\Models\AuditEvent;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;

// "Export" on the new back office's lists (as on every current list): what the list shows, with its search and filters,
// as Excel, CSV or PDF through the same Exporter (and its audit row).

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Warehouse::find(Till::where('branch_id', $this->branches['HQ']->id)->value('warehouse_id'));
    $this->milo = makeProduct($this->owner, 'MILO-400', 'Milo 400g', retail: '45.00');
    $this->sugar = makeProduct($this->owner, 'SUGAR-1KG', 'Sugar 1kg', retail: '18.50');
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->hq, $this->milo, $this->milo->units->firstWhere('is_base', true), '40', 'opening', '');
    $this->owner = withMfa($this->owner->fresh());
});

it('exports each rebuilt list as CSV, with the columns the screen shows', function () {
    $this->actingAs($this->owner, 'web');
    foreach (['sales' => 'Receipt', 'shifts' => 'Over / short', 'drawer' => 'Approved by', 'products' => 'On hand', 'stock' => 'Last movement', 'movements' => 'Base qty'] as $list => $heading) {
        $csv = $this->get("/office-api/export/{$list}?format=csv")->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();
        expect($csv)->toContain($heading);
    }
    $products = $this->get('/office-api/export/products?format=csv')->streamedContent();
    expect($products)->toContain('MILO-400')->toContain('45.00')->toContain('SUGAR-1KG');
    expect($this->get('/office-api/export/movements?format=csv')->streamedContent())->toContain('Opening');
});

it('exports only what the search and filters pick, and records the export', function () {
    $this->actingAs($this->owner, 'web');
    $csv = $this->get('/office-api/export/products?format=csv&q=sugar')->assertOk()->streamedContent();
    expect($csv)->toContain('SUGAR-1KG')->not->toContain('MILO-400');
    expect(AuditEvent::where('action', 'like', '%export%')->exists())->toBeTrue();
    $this->get('/office-api/export/stock?format=xlsx&level=in')->assertOk();
    $this->get('/office-api/export/stock?format=pdf&level=in')->assertOk();
    $this->get('/office-api/export/stock?format=doc')->assertStatus(422);
});

it('refuses a list the person may not see', function () {
    $teller = withMfa(staff($this->owner, 'esi', 2, 'teller', ScopeType::Branch, $this->branches['HQ']->id));
    $this->actingAs($teller, 'web');
    $this->get('/office-api/export/stock?format=csv')->assertForbidden();
    $this->get('/office-api/export/sales?format=csv')->assertForbidden();
    $this->get('/office-api/export/nothing?format=csv')->assertNotFound();
});
