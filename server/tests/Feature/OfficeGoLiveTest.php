<?php

use App\Domain\Imports\ImportService;
use App\Models\BackupRun;
use App\Models\DataImport;
use App\Models\GoLivePlan;
use App\Models\Industry;
use App\Models\ParallelDay;
use App\Models\Product;
use App\Security\ScopeType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;

// Go-live and Import data in the new back office (part 24).

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner, [['code' => 'SM', 'name' => 'Supermarket', 'is_hq' => true, 'tills' => 1, 'modules' => ['grocery']]]);
    $this->grocery = Industry::where('module_key', 'grocery')->firstOrFail();
    withMfa($this->owner);
    $this->csv = fn (string $body = "sku,name,price_retail\n,Rice 5kg,90\n,Oil 1L,40\n") => UploadedFile::fake()->createWithContent('products.csv', $body);
});

it('uploads a file, checks it (nothing saved), imports it once and refuses it twice', function () {
    $this->actingAs($this->owner, 'web');
    $page = $this->getJson('/office-api/system/import?type=products')->assertOk()->json();
    expect($page['type'])->toBe('products')->and($page['columns'])->not->toBeEmpty()->and($page['asks_industry'])->toBeTrue();
    $this->get('/office-api/system/import/download/template?type=products&format=csv')->assertOk();
    $this->get('/office-api/system/import/download/guide?type=products')->assertOk();

    $up = $this->post('/office-api/system/import/upload', ['type' => 'products', 'file' => ($this->csv)()], ['Accept' => 'application/json'])->assertOk()->json();
    expect($up)->toMatchArray(['total' => 2, 'headings' => ['sku', 'name', 'price_retail']])->and($up['rows'][0]['name'])->toBe('Rice 5kg');
    $body = ['type' => 'products', 'token' => $up['token'], 'industry' => $this->grocery->id];
    $this->postJson('/office-api/system/import/run', ['type' => 'products', 'token' => $up['token'], 'industry' => ''])->assertStatus(422); // which industry
    $checked = $this->postJson('/office-api/system/import/run', $body + ['commit' => false])->assertOk()->json();
    expect($checked['import'])->toMatchArray(['status' => 'checked', 'total' => 2, 'errors' => 0])->and(Product::count())->toBe(0);
    $done = $this->postJson("/office-api/system/import/{$checked['import']['id']}/import")->assertOk()->json();
    expect($done['import']['status'])->toBe('imported')->and(Product::whereIn('name', ['RICE 5KG', 'OIL 1L'])->count())->toBe(2);

    $again = $this->post('/office-api/system/import/upload', ['type' => 'products', 'file' => ($this->csv)()], ['Accept' => 'application/json'])->json('token');
    $dup = $this->postJson('/office-api/system/import/run', ['type' => 'products', 'token' => $again, 'industry' => $this->grocery->id, 'commit' => true])->assertOk()->json();
    expect($dup['duplicate'])->toContain('already imported')->and(Product::count())->toBe(2);

    // A row with an error: the error report lists it.
    $bad = $this->post('/office-api/system/import/upload', ['type' => 'products', 'file' => ($this->csv)("sku,name,price_retail\n,,abc\n")], ['Accept' => 'application/json'])->json('token');
    $r = $this->postJson('/office-api/system/import/run', ['type' => 'products', 'token' => $bad, 'industry' => $this->grocery->id])->assertOk()->json('import');
    expect($r['errors'])->toBe(1);
    $this->get("/office-api/system/import/{$r['id']}/errors")->assertOk();
    expect(collect($this->getJson('/office-api/system/import?type=products')->json('history'))->pluck('id'))->toContain($r['id']);

    // A token belongs to the person who uploaded it.
    $other = withMfa(staff($this->owner, 'kojo', 5, 'general_manager'));
    $this->actingAs($other, 'web');
    if ($this->getJson('/office-api/system/import?type=products')->status() === 200) {
        $this->postJson('/office-api/system/import/run', $body)->assertStatus(422);
    }
});

it('runs a large file in the background and follows its progress', function () {
    ImportService::$backgroundFrom = 2; // a "large" file here is three rows
    $this->actingAs($this->owner, 'web');
    $up = $this->post('/office-api/system/import/upload', ['type' => 'products', 'file' => ($this->csv)("sku,name,price_retail\n,Rice 5kg,90\n,Oil 1L,40\n,Sugar 1kg,25\n")],
        ['Accept' => 'application/json'])->json('token');
    $q = $this->postJson('/office-api/system/import/run', ['type' => 'products', 'token' => $up, 'industry' => $this->grocery->id, 'commit' => true])->assertOk()->json('import');
    expect($q)->toMatchArray(['status' => 'queued', 'running' => true])->and($q['progress']['label'])->toContain('Waiting')->and(Product::count())->toBe(0);
    Artisan::call('erp:imports');
    $after = $this->getJson("/office-api/system/import/{$q['id']}")->assertOk()->json();
    expect($after['import'])->toMatchArray(['status' => 'imported', 'running' => false, 'ok' => 3])->and($after['message'])->toContain('Imported 3');
    expect(DataImport::sole()->status)->toBe('imported');
    ImportService::$backgroundFrom = 500;
});

it('plans going live, ticks steps, records the old figures and a parallel day, and goes live with the reason', function () {
    $this->actingAs($this->owner, 'web');
    $g = $this->getJson('/office-api/system/go-live')->assertOk()->json();
    expect($g)->toMatchArray(['exists' => false, 'status' => 'planning'])->and($g['steps'])->not->toBeEmpty()->and($g['figures']['rows'])->not->toBeEmpty();
    $this->postJson('/office-api/system/go-live', ['action' => 'plan', 'sources' => [], 'cutover_mode' => 'parallel', 'pilot_mode' => 'one_shop'])->assertStatus(422);
    $saved = $this->postJson('/office-api/system/go-live', ['action' => 'plan', 'sources' => ['excel'], 'cutover_mode' => 'parallel', 'pilot_mode' => 'one_shop',
        'go_live_on' => now()->toDateString(), 'support_email' => 'support@example.test', 'pilot_report_time' => '19:00'])->assertOk()->json('golive');
    expect($saved)->toMatchArray(['exists' => true])->and($saved['plan']['support_email'])->toBe('support@example.test')->and($saved['parallel'])->toBe([]);

    $manual = collect($saved['steps'])->firstWhere('manual', true);
    if ($manual) {
        $ticked = $this->postJson('/office-api/system/go-live', ['action' => 'step', 'key' => $manual['key'], 'do' => 'done', 'note' => 'Done with the owner'])->assertOk()->json('golive');
        expect(collect($ticked['steps'])->firstWhere('key', $manual['key']))->toMatchArray(['done' => true, 'note' => 'Done with the owner']);
    }
    $money = collect($saved['old_totals'])->first()['key'];
    $this->postJson('/office-api/system/go-live', ['action' => 'old_totals', 'figures' => [$money => '1250.50']])->assertOk();
    expect(GoLivePlan::sole()->old_totals[$money])->not->toBeNull();

    $this->postJson('/office-api/system/go-live', ['action' => 'parallel_day', 'branch_id' => $this->b['SM']->id, 'day' => now()->toDateString()])->assertStatus(422);
    $day = $this->postJson('/office-api/system/go-live', ['action' => 'parallel_day', 'branch_id' => $this->b['SM']->id, 'day' => now()->toDateString(), 'old_sales' => '0'])->assertOk()->json('golive');
    expect($day['parallel'])->toHaveCount(1)->and(ParallelDay::count())->toBe(1);
    expect($this->postJson('/office-api/system/go-live', ['action' => 'report'])->assertOk()->json('golive.report.title'))->not->toBeEmpty();

    expect($day['open_steps'])->not->toBeEmpty();
    $this->postJson('/office-api/system/go-live', ['action' => 'go_live'])->assertStatus(422); // steps are open: say why
    expect($this->postJson('/office-api/system/go-live', ['action' => 'go_live', 'reason' => 'x'])->assertStatus(422)->json('detail'))->toContain('go-live backup');
    $run = BackupRun::create(['file_name' => 'synclytics-test.synbak', 'status' => 'ok', 'started_by' => 'Test', 'started_at' => now(), 'finished_at' => now()]);
    GoLivePlan::sole()->forceFill(['snapshot_backup_id' => $run->id])->save();
    expect($this->getJson('/office-api/system/go-live')->json('snapshot.file'))->toBe('synclytics-test.synbak');
    $live = $this->postJson('/office-api/system/go-live', ['action' => 'go_live', 'reason' => 'Owner decided; the rest follows this week'])->assertOk()->json('golive');
    expect($live)->toMatchArray(['status' => 'live'])->and($live['actions'])->toMatchArray(['go_live' => false, 'roll_back' => true]);
    $this->postJson('/office-api/system/go-live', ['action' => 'roll_back'])->assertStatus(422);
    expect($this->postJson('/office-api/system/go-live', ['action' => 'roll_back', 'reason' => 'Tills not ready'])->assertOk()->json('golive.status'))->toBe('rolled_back');

    $cashier = withMfa(staff($this->owner, 'efua', 3, 'cashier', ScopeType::Branch, $this->b['SM']->id));
    $this->actingAs($cashier, 'web');
    $this->getJson('/office-api/system/go-live')->assertForbidden();
});
