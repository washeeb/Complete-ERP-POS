<?php

use App\Domain\Backup\BackupCrypto;
use App\Domain\Backup\BackupService;
use App\Domain\Backup\Destinations;
use App\Domain\Devices\DeviceService;
use App\Domain\Fiscal\FiscalAdapter;
use App\Domain\Fiscal\FiscalRelay;
use App\Domain\Fiscal\FiscalService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Support\DomainException;
use App\Domain\Support\SystemHealth;
use App\Filament\Pages\Backups;
use App\Filament\Pages\SystemHealthPage;
use App\Filament\Resources\FiscalSubmissions\FiscalSubmissionResource;
use App\Models\BackupDestination;
use App\Models\FiscalSubmission;
use App\Models\PriceList;
use App\Models\Sale;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = $this->branches['HQ'];
    $this->o = actorFor($this->owner);
    $this->wh = Warehouse::where('branch_id', $this->hq->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    app(InventoryService::class)->adjust($this->o, $this->wh, $this->milo, $this->milo->units->first(), '50', 'opening', '', null, '40.00');
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $this->till = Till::where('branch_id', $this->hq->id)->first();
    [$this->device] = app(DeviceService::class)->register($this->o, ['branch_id' => $this->hq->id, 'till_id' => $this->till->id, 'name' => 'T1', 'kind' => 'pos']);
    $this->device->forceFill(['status' => 'active'])->save();
    $this->cashier = staff($this->owner, 'efua', 2, 'cashier', ScopeType::Till, $this->till->id);
    $this->shift = app(ShiftService::class)->open($this->device, $this->cashier, 0);
    $this->seq = 0;
    $this->tmp = storage_path('framework/testing/hardening-'.Str::random(6));
    File::ensureDirectoryExists($this->tmp);
    config(['backup.passphrase' => 'correct horse battery staple', 'backup.local_dir' => $this->tmp.'/local',
        'backup.pg_bin' => env('BACKUP_PG_BIN', PHP_OS_FAMILY === 'Windows' ? 'C:\Program Files\PostgreSQL\17\bin' : '')]);
});

afterEach(fn () => File::deleteDirectory($this->tmp));

function hardSale($t, string $qty = '2', array $extra = []): Sale
{
    $unit = $t->milo->units->first();
    $price = app(PriceResolver::class)->resolve($t->retail, $t->milo->id, $unit->id, Qty::toMilli($qty), now())->amount_minor;
    $calc = PromotionEngine::compute(['lines' => [['key' => '0', 'product_id' => $t->milo->id, 'category_id' => null, 'qty_milli' => Qty::toMilli($qty), 'unit_price_minor' => $price,
        'tax' => $t->milo->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all(), 'manual' => null]],
        'inclusive' => true, 'coupon' => null, 'sale_discount_bp' => 0, 'promotions' => []]);

    return app(SaleService::class)->post($t->device, $t->cashier, ['id' => (string) Str::uuid7(), 'device_seq' => ++$t->seq, 'occurred_at' => now()->toIso8601String(),
        'price_list_id' => $t->retail->id, 'shift_id' => $t->shift->id, 'lines' => [['product_id' => $t->milo->id, 'product_uom_id' => $unit->id, 'qty' => $qty, 'unit_price_minor' => $price,
            'line_total_minor' => $calc['lines'][0]['total']]], 'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $calc['total']],
        'payments' => [['method' => 'cash', 'amount_minor' => $calc['total'], 'tendered_minor' => $calc['total']]]] + $extra)['sale'];
}

it('encrypts backups so a wrong passphrase or a changed byte is caught', function () {
    $plain = $this->tmp.'/plain.bin';
    file_put_contents($plain, random_bytes(2_500_000)); // spans several chunks
    BackupCrypto::encrypt($plain, $this->tmp.'/x.synbak', 'correct horse battery staple');
    expect(file_get_contents($this->tmp.'/x.synbak'))->toStartWith("SYNBAK1\n");
    BackupCrypto::decrypt($this->tmp.'/x.synbak', $this->tmp.'/back.bin', 'correct horse battery staple');
    expect(hash_file('sha256', $this->tmp.'/back.bin'))->toBe(hash_file('sha256', $plain))
        ->and(fn () => BackupCrypto::decrypt($this->tmp.'/x.synbak', $this->tmp.'/y.bin', 'wrong passphrase!!'))->toThrow(DomainException::class, 'wrong passphrase')
        ->and(fn () => BackupCrypto::encrypt($plain, $this->tmp.'/z', 'short'))->toThrow(DomainException::class, 'erp:backup:key');
    $bytes = file_get_contents($this->tmp.'/x.synbak');
    $bytes[100] = chr(ord($bytes[100]) ^ 1);
    file_put_contents($this->tmp.'/x.synbak', $bytes);
    expect(fn () => BackupCrypto::decrypt($this->tmp.'/x.synbak', $this->tmp.'/y.bin', 'correct horse battery staple'))->toThrow(DomainException::class);
    // Truncated (copy cut off): no final chunk.
    file_put_contents($this->tmp.'/cut.synbak', substr($bytes, 0, 1_200_000));
});

it('signs S3 requests exactly as AWS documents, and talks to Google Drive with a refresh token', function () {
    // AWS Signature Version 4 example "GET Object" from the S3 API reference.
    $h = Destinations::s3Sign('GET', 'examplebucket.s3.amazonaws.com', '/test.txt', [], '', 'us-east-1', 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        '20130524T000000Z', ['Range' => 'bytes=0-9']);
    expect($h['Authorization'])->toBe('AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41');

    Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.x']), 'www.googleapis.com/upload/*' => Http::response('', 200, ['Location' => 'https://www.googleapis.com/upload/session/1']),
        'www.googleapis.com/upload/session/*' => Http::response(['id' => 'f1']), 'www.googleapis.com/drive/v3/files*' => Http::response(['files' => [['id' => 'f1', 'name' => 'synclytics-a-20261029-020000.synbak']]])]);
    $f = $this->tmp.'/f.synbak';
    file_put_contents($f, 'x');
    $cfg = ['client_id' => 'c', 'client_secret' => 's', 'refresh_token' => 'r', 'folder_id' => 'FOLDER'];
    Destinations::put('gdrive', $cfg, $f, 'synclytics-a-20261029-020000.synbak');
    expect(Destinations::list('gdrive', $cfg))->toBe(['synclytics-a-20261029-020000.synbak']);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'uploadType=resumable') && $r['parents'] === ['FOLDER'] && $r->hasHeader('Authorization', 'Bearer ya29.x'));
});

it('makes a real encrypted backup, copies it to a USB-style folder, keeps the newest, and verifies it reads back', function () {
    $usb = $this->tmp.'/usb';
    File::ensureDirectoryExists($usb);
    expect(fn () => Destinations::test('folder', ['path' => $this->tmp.'/not-plugged-in']))->toThrow(DomainException::class, 'USB drive');
    Destinations::test('folder', ['path' => $usb]);
    BackupDestination::create(['name' => 'USB drive A', 'type' => 'folder', 'config' => ['path' => $usb], 'keep' => 2, 'is_active' => true]);
    BackupDestination::create(['name' => 'Gone', 'type' => 'folder', 'config' => ['path' => $this->tmp.'/missing'], 'keep' => 2, 'is_active' => true]);
    foreach (['20261001-020000', '20261002-020000'] as $old) {
        file_put_contents("{$usb}/synclytics-hq-{$old}.synbak", 'old');
    }

    $run = app(BackupService::class)->run('Test');
    expect($run->status)->toBe('partial')->and($run->size_bytes)->toBeGreaterThan(1000)
        ->and(collect($run->destinations)->firstWhere('name', 'USB drive A')['ok'])->toBeTrue()
        ->and(collect($run->destinations)->firstWhere('name', 'Gone')['ok'])->toBeFalse()
        ->and(glob("{$usb}/*.synbak"))->toHaveCount(2) // the newest two kept
        ->and(file_exists("{$usb}/{$run->file_name}"))->toBeTrue()
        ->and(DB::table('backup_destinations')->where('name', 'USB drive A')->value('config'))->not->toContain($usb); // encrypted at rest

    $v = app(BackupService::class)->verify("{$usb}/{$run->file_name}");
    expect($v['manifest']['counts'])->toHaveKey('products')->and($v['manifest']['dump_sha256'])->toHaveLength(64);
    config(['backup.passphrase' => 'another passphrase here']);
    expect(fn () => app(BackupService::class)->verify("{$usb}/{$run->file_name}"))->toThrow(DomainException::class, 'wrong passphrase');
    config(['backup.passphrase' => 'correct horse battery staple']);
    $checked = app(BackupService::class)->verifyLatest();
    // Where the database user may create a scratch database (CI), the full restore drill runs and every count matches.
    expect($checked->verify_result)->toMatch('/^(Restore drill passed|Readable: \\d+ tables counted; restore drill not run: .*CREATEDB)/')->and($checked->verified_at)->not->toBeNull();
})->skip(fn () => ! is_executable(rtrim((string) env('BACKUP_PG_BIN', PHP_OS_FAMILY === 'Windows' ? 'C:\Program Files\PostgreSQL\17\bin' : '/usr/lib/postgresql/17/bin'), '\\/').'/pg_dump'.(PHP_OS_FAMILY === 'Windows' ? '.exe' : ''))
    && trim((string) shell_exec('command -v pg_dump 2>/dev/null')) === '', 'pg_dump not installed');

it('reports to GRA in the mode chosen: each sale now, a daily batch, or invoices only', function () {
    $fake = new class implements FiscalAdapter
    {
        public array $sent = [];

        public array $batches = [];

        public bool $down = false;

        public function submit(array $document): array
        {
            if ($this->down) {
                throw new DomainException('GRA E-VAT not reachable', 'fiscal_unreachable');
            }
            $this->sent[] = $document;

            return ['reference' => 'GRA-'.count($this->sent), 'qr' => 'https://evat.gra.gov.gh/verify/'.count($this->sent), 'raw' => ['ok' => true]];
        }

        public function submitBatch(array $documents): array
        {
            $this->batches[] = $documents;

            return array_map(fn ($d, $i) => $i === 1 ? ['ok' => false, 'reference' => null, 'qr' => null, 'error' => 'Bad TIN', 'raw' => []]
                : ['ok' => true, 'reference' => 'B-'.$d['number'], 'qr' => null, 'error' => null, 'raw' => []], $documents, array_keys($documents));
        }
    };
    app()->instance('fiscal.fake', $fake);
    $fiscal = app(FiscalService::class);
    $settings = app(Settings::class);

    // Off (default): nothing recorded.
    expect($fiscal->submitSaleNow(hardSale($this)))->toBeNull();

    // Realtime: the till's sale gets GRA's code at once; with GRA down it waits and is retried.
    $settings->set($this->o, 'evat.mode', 'realtime');
    $sub = $fiscal->submitSaleNow($sale = hardSale($this));
    expect($sub->status)->toBe('submitted')->and($sub->gra_reference)->toBe('GRA-1')->and($fake->sent[0]['type'])->toBe('INVOICE')
        ->and($fake->sent[0]['items'][0])->toMatchArray(['code' => 'MILO', 'qty' => 2.0, 'total' => 116.0])
        ->and(collect($fake->sent[0]['taxes'])->pluck('code')->all())->not->toBeEmpty()
        ->and($fake->sent[0]['total'])->toBe(116.0);
    $fake->down = true;
    $sub2 = $fiscal->submitSaleNow(hardSale($this));
    expect($sub2->status)->toBe('pending')->and($sub2->next_attempt_at->isFuture())->toBeTrue();
    $fake->down = false;
    $this->travel(2)->minutes();
    app(FiscalRelay::class)->run();
    expect($sub2->fresh()->status)->toBe('submitted')
        ->and(FiscalSubmission::where('doc_id', $sale->id)->count())->toBe(1); // the relay does not report a sale twice

    // Daily batch: till sales wait for the batch; one refused by GRA is marked for a person to look at.
    $settings->set($this->o, 'evat.mode', 'daily');
    $a = hardSale($this);
    $b = hardSale($this, '1');
    app(FiscalRelay::class)->run();
    expect(FiscalSubmission::whereIn('doc_id', [$a->id, $b->id])->pluck('status')->unique()->all())->toBe(['pending'])
        ->and($fiscal->batch($this->tenant->id))->toBe(1)
        ->and(FiscalSubmission::where('doc_id', $a->id)->value('gra_reference'))->toBe('B-'.$a->receipt_no)
        ->and(FiscalSubmission::where('doc_id', $b->id)->value('status'))->toBe('rejected');

    // Invoices only: till sales are not reported.
    $settings->set($this->o, 'evat.mode', 'invoices');
    $c = hardSale($this);
    app(FiscalRelay::class)->run();
    expect(FiscalSubmission::where('doc_id', $c->id)->exists())->toBeFalse()
        ->and(fn () => $settings->set($this->o, 'evat.mode', 'weekly'))->toThrow(DomainException::class);
});

it('checks the system health and sends browser security headers', function () {
    $rows = collect(app(SystemHealth::class)->run())->keyBy('check');
    expect($rows['Database']['status'])->toBe('ok')->and($rows['Scheduler']['status'])->toBe('fail')->and($rows['Backups']['status'])->toBe('fail')
        ->and($rows['Backup passphrase']['status'])->toBe('ok');
    $this->artisan('erp:health --beat')->assertSuccessful();
    expect(collect(app(SystemHealth::class)->run())->firstWhere('check', 'Scheduler')['status'])->toBe('ok');
    $this->artisan('erp:health')->assertFailed(); // no backup yet

    $this->get('/admin/login')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    expect($this->get('/admin/login')->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'");
    $this->getJson('/api/public/v1/openapi.json')->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('shows backups, health and GRA E-VAT to the right people', function () {
    $this->actingAs(withMfa($this->owner));
    foreach ([Backups::getUrl(), SystemHealthPage::getUrl(), FiscalSubmissionResource::getUrl()] as $url) {
        $this->get($url)->assertOk();
    }
    $this->flushSession();
    $this->actingAs(withMfa($this->cashier));
    foreach ([Backups::getUrl(), SystemHealthPage::getUrl(), FiscalSubmissionResource::getUrl()] as $url) {
        $this->get($url)->assertForbidden();
    }
});
