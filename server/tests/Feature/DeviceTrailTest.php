<?php

use App\Domain\Access\AccessService;
use App\Domain\Devices\DeviceService;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\Till;
use App\Security\ScopeType;
use App\Support\ClientInfo;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;

it('tells browsers apart and reads what the desktop till reports, refusing junk', function () {
    expect(ClientInfo::browser('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0'))
        ->toBe('Edge 141 on Windows')
        ->and(ClientInfo::browser('Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36'))->toBe('Chrome 140 on Android');

    $r = Request::create('/x', 'GET', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/141.0 Safari/537.36',
        'HTTP_X_SES_CLIENT' => json_encode(['computer' => 'FRONT-PC', 'mac' => 'a4-bb-6d-01-02-03', 'local_ip' => '192.168.1.20', 'app' => 'SES Labs Till 0.25.0'])]);
    expect(ClientInfo::from($r))->toBe(['browser' => 'Chrome 141 on Windows', 'computer' => 'FRONT-PC', 'mac' => 'A4:BB:6D:01:02:03', 'local_ip' => '192.168.1.20', 'app' => 'SES Labs Till 0.25.0'])
        ->and(ClientInfo::summary(ClientInfo::from($r), '41.66.1.2'))->toBe('FRONT-PC (192.168.1.20) · Chrome 141 on Windows · from 41.66.1.2');

    $junk = Request::create('/x', 'GET', server: ['HTTP_X_SES_CLIENT' => json_encode(['computer' => '<script>', 'mac' => 'nope', 'local_ip' => '999.1.1.1'])]);
    expect(ClientInfo::from($junk))->not->toHaveKeys(['computer', 'mac', 'local_ip']);
});

it('records the till computer, its MAC and the real address (behind the web server) for till sign-ins and on the device', function () {
    [, $owner] = installCompany();
    $branches = setUpBranches($owner);
    $till = Till::where('branch_id', $branches['HQ']->id)->first();
    [$device, $code] = app(DeviceService::class)->register(actorFor($owner), ['branch_id' => $branches['HQ']->id, 'till_id' => $till->id, 'name' => 'Till PC', 'kind' => 'pos']);
    $token = $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->json('token');
    $cashier = staff($owner, 'esi', 2, 'teller', ScopeType::Till, $till->id);
    app(AccessService::class)->setPin(actorFor($owner), $cashier, '4826');

    $this->withToken($token)->withHeaders(['X-Forwarded-For' => '41.66.1.2', 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/141.0 Safari/537.36 Edg/141.0',
        'X-SES-Client' => json_encode(['computer' => 'FRONT-PC', 'mac' => 'A4:BB:6D:01:02:03', 'local_ip' => '192.168.1.20', 'app' => 'SES Labs Till 0.25.0'])])
        ->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '4826'])->assertOk();

    $login = AuditEvent::where('action', 'pos.login')->latest('seq')->firstOrFail();
    expect($login->ip)->toBe('41.66.1.2')->and($login->client['computer'])->toBe('FRONT-PC')->and($login->client['mac'])->toBe('A4:BB:6D:01:02:03')
        ->and($login->client['browser'])->toBe('Edge 141 on Windows');
    $d = Device::find($device->id);
    expect($d->last_ip)->toBe('41.66.1.2')->and($d->last_client['computer'])->toBe('FRONT-PC');
});

it('records back-office sign-ins and sign-outs with the browser', function () {
    [, $owner] = installCompany();
    $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) Version/18.0 Safari/605.1.15']);
    app()->instance('request', Request::create('/admin/login', 'POST', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) Version/18.0 Safari/605.1.15']));
    event(new Login('web', $owner, false));
    event(new Logout('web', $owner));
    $rows = AuditEvent::whereIn('action', ['auth.signed_in', 'auth.signed_out'])->orderBy('seq')->get();
    expect($rows->pluck('action')->all())->toBe(['auth.signed_in', 'auth.signed_out'])
        ->and($rows[0]->client['browser'])->toBe('Safari 18 on Mac')->and($rows[0]->subject_id)->toBe($owner->id);
});
