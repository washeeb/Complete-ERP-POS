<?php

use Illuminate\Support\Facades\DB;

it('answers the health endpoint', function () {
    $this->get('/up')->assertOk();
});

it('runs on PostgreSQL, never a silent SQLite fallback', function () {
    // SQLite cannot prove row locks or concurrent stock/sync behaviour.
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and((int) DB::selectOne('show server_version_num')->server_version_num)->toBeGreaterThanOrEqual(170000);
});

it('serves the back-office login page', function () {
    $this->get('/admin/login')->assertOk();
});

it('redirects guests away from the back office', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});
