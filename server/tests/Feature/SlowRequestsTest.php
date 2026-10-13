<?php

use App\Filament\Pages\SystemHealthPage;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

it('notes slow pages with their database reads and lists them under System → Health', function () {
    [, $owner] = installCompany();
    setUpBranches($owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($owner), 'web');

    config(['erp.slow_request_ms' => 100000]); // nothing is that slow
    $this->get('/admin/products')->assertOk();
    expect(DB::table('slow_requests')->count())->toBe(0);

    config(['erp.slow_request_ms' => 0]); // everything counts as slow
    $this->get('/admin/products?tableSearch=private-term')->assertOk();
    app()->terminate();
    $row = DB::table('slow_requests')->latest('id')->first();
    expect($row)->not->toBeNull()
        ->and($row->path)->toBe('/admin/products')->not->toContain('private-term') // never the query string
        ->and($row->queries)->toBeGreaterThan(0)
        ->and($row->tenant_id)->toBe($owner->tenant_id);

    config(['erp.slow_request_ms' => 100000]);
    $this->get(SystemHealthPage::getUrl())->assertOk()->assertSee('Slow pages (last 7 days)')->assertSee('/admin/products');
});
