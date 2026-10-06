<?php

use App\Domain\Devices\DeviceService;
use App\Domain\Devices\DeviceSignature;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\TaxCalculator;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Till;
use App\Support\Qty;
use Illuminate\Support\Str;

/**
 * Spec §4.3 for the till ⇄ server path: work done offline is queued on the till, signed with the
 * device key, and applied later. Node-to-node sync scenarios are in NodeSyncTest.
 */
final class FakeTill
{
    public string $token;

    public array $grant;

    public int $seq = 0;

    public ?string $shiftId = null;

    public function __construct(public $test, public $device, public $privateKey) {}

    /** Sign the exact body the way the browser does (ECDSA P-256 SHA-256, raw r||s). */
    public function sync(array $events, ?string $badSignature = null)
    {
        $body = json_encode(['events' => $events]);
        openssl_sign($body, $der, $this->privateKey, OPENSSL_ALGO_SHA256);
        $sig = $badSignature ?? base64_encode(DeviceSignature::derToRaw($der));
        app('auth')->forgetGuards();

        return $this->test->call('POST', '/api/v1/pos/sync', [], [], [], [
            'HTTP_AUTHORIZATION' => "Bearer {$this->token}", 'HTTP_X_DEVICE_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    public function openShift(int $float, string $at): array
    {
        $this->shiftId = (string) Str::uuid7();

        return ['id' => $this->shiftId, 'type' => 'shift_open', 'grant' => $this->grant,
            'payload' => ['id' => $this->shiftId, 'opening_float_minor' => $float, 'opened_at' => $at]];
    }

    public function closeShift(int $counted, string $at): array
    {
        return ['id' => (string) Str::uuid7(), 'type' => 'shift_close', 'grant' => $this->grant,
            'payload' => ['shift_id' => $this->shiftId, 'counted_cash_minor' => $counted, 'closed_at' => $at]];
    }

    /** @param list<array{0: Product, 1: string, 2: ?int}> $items [product, qty, price override] */
    public function sale(array $items, string $at): array
    {
        $list = PriceList::where('code', 'RETAIL')->first();
        $lines = [];
        $net = $tax = $total = 0;
        foreach ($items as [$product, $qty, $override]) {
            $unit = $product->units->firstWhere('is_base', true);
            $price = $override ?? app(PriceResolver::class)->resolve($list, $product->id, $unit->id, Qty::toMilli($qty), now())->amount_minor;
            $rates = $product->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->all();
            $t = TaxCalculator::line(Qty::divRound(Qty::toMilli($qty) * $price, 1000), $rates, true);
            $lines[] = ['product_id' => $product->id, 'product_uom_id' => $unit->id, 'qty' => $qty, 'unit_price_minor' => $price, 'line_total_minor' => $t['gross']];
            $net += $t['net'];
            $tax += $t['tax'];
            $total += $t['gross'];
        }
        $id = (string) Str::uuid7();

        return ['id' => $id, 'type' => 'sale', 'grant' => $this->grant, 'payload' => [
            'id' => $id, 'device_seq' => ++$this->seq, 'occurred_at' => $at, 'price_list_id' => $list->id, 'shift_id' => $this->shiftId,
            'lines' => $lines, 'totals' => ['net_minor' => $net, 'tax_minor' => $tax, 'total_minor' => $total],
            'payments' => [['method' => 'cash', 'amount_minor' => $total, 'tendered_minor' => $total]],
        ]];
    }
}

function fakeTill($test, Till $till, string $username, string $pin): FakeTill
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => base_path('tests/fixtures/openssl.cnf')]);
    $pem = openssl_pkey_get_details($key)['key'];
    $spki = trim(preg_replace('/-----[^-]+-----|\s+/', '', $pem));

    [$device, $code] = app(DeviceService::class)->register(actorFor($test->owner), [
        'branch_id' => $till->branch_id, 'till_id' => $till->id, 'name' => 'Till '.Str::random(4), 'kind' => 'pos']);
    $ft = new FakeTill($test, $device, $key);
    $ft->token = $test->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => $spki])->assertCreated()->json('token');
    app('auth')->forgetGuards();
    $login = $test->withToken($ft->token)->postJson('/api/v1/pos/login', ['username' => $username, 'pin' => $pin])->assertOk()->json();
    $ft->grant = $login['offline_grant'];
    $test->withoutToken();
    app('auth')->forgetGuards();

    return $ft;
}
