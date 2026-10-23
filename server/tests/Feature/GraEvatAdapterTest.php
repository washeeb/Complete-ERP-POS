<?php

use App\Domain\Fiscal\GraEvatAdapter;
use App\Domain\Support\DomainException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/** GRA E-VAT API VER 8.2 (VSDC): the address, the security key, the fields and GRA's answers, as published by GRA. */
beforeEach(function () {
    config(['fiscal.gra' => ['url' => 'https://vsdcstaging.vat-gh.com/vsdc/', 'key' => 'secret-key=', 'tin' => 'C0011111111', 'reference' => 'C0011111111-001',
        'accounts' => ['C0022222222' => ['reference' => 'C0022222222-001', 'key' => 'pharmacy-key=']], 'timeout' => 8]]);
    $this->doc = ['type' => 'INVOICE', 'number' => 'HQ-T01A-000123', 'original_number' => null, 'date' => '2026-10-23', 'datetime' => '2026-10-23T10:15:00Z',
        'user' => 'Esi Cashier', 'discount' => 0, 'currency' => 'GHS', 'prices_include_tax' => true,
        'seller' => ['tin' => 'C0011111111', 'name' => 'Asante Holdings', 'branch' => 'HQ'], 'buyer' => ['tin' => null, 'name' => null, 'phone' => null],
        'items' => [
            ['code' => 'MILO', 'description' => 'Milo 400g', 'qty' => 2, 'unit_price' => 58.0, 'discount' => 0, 'net' => 96.0,
                'taxes' => [['code' => 'VAT', 'rate' => 15, 'amount' => 15.0], ['code' => 'NHIL', 'rate' => 2.5, 'amount' => 2.5], ['code' => 'GETFUND', 'rate' => 2.5, 'amount' => 2.5]], 'total' => 116.0],
            ['code' => 'PARA', 'description' => 'Paracetamol', 'qty' => 1, 'unit_price' => 5.0, 'discount' => 0, 'net' => 5.0, 'taxes' => [], 'total' => 5.0],
        ],
        'net' => 101.0, 'taxes' => [], 'total' => 121.0];
    $this->ok = ['response' => ['distributor_tin' => 'C0011111111', 'mesaage' => ['num' => 'HQ-T01A-000123', 'ysdcid' => 'EV-261023-001', 'ysdcrecnum' => '83NS',
        'ysdcintdata' => 'NUGABIDWABF5MRRN2EXDUSJ7M4', 'ysdcregsig' => 'E4OFBGZX2MT6YBTQ', 'ysdcmrc' => '2b60f37f175c7c', 'ysdctime' => '2026/10/23 10:15:02',
        'flag' => 'INVOICE', 'ysdcitems' => 2], 'qr_code' => 'https://evat.example/verify?sig=E4OFBGZX2MT6YBTQ', 'status' => 'SUCCESS']];
});

it('sends an invoice in GRA’s VSDC format and keeps GRA’s signature for the receipt', function () {
    Http::fake(['vsdcstaging.vat-gh.com/*' => Http::response($this->ok)]);
    $r = app(GraEvatAdapter::class)->submit($this->doc);
    expect($r['reference'])->toBe('EV-261023-001 83NS')->and($r['qr'])->toBe('https://evat.example/verify?sig=E4OFBGZX2MT6YBTQ')
        ->and(GraEvatAdapter::signature($r['raw']))->toMatchArray(['sdc_id' => 'EV-261023-001', 'receipt' => '83NS', 'signature' => 'E4OFBGZX2MT6YBTQ', 'mrc' => '2b60f37f175c7c']);
    Http::assertSent(function (HttpRequest $req) {
        $b = $req->data();

        return $req->url() === 'https://vsdcstaging.vat-gh.com/vsdc/api/v1/taxpayer/C0011111111-001/invoice' && $req->hasHeader('security_key', 'secret-key=')
            && $b['flag'] === 'INVOICE' && $b['calculationType'] === 'INCLUSIVE' && $b['invoiceNumber'] === 'HQ-T01A-000123' && $b['totalVat'] == 15.0 && $b['totalLevy'] == 5.0
            && $b['totalAmount'] == 121.0 && $b['businessPartnerName'] === 'Cash customer' && $b['businessPartnerTin'] === 'C0000000000' && $b['userName'] === 'Esi Cashier'
            && $b['items'][0]['levyAmountA'] == 2.5 && $b['items'][0]['levyAmountB'] == 2.5 && $b['items'][0]['levyAmountC'] == 0 && $b['items'][0]['itemCode'] === 'MILO'
            && $b['items'][1]['itemCategory'] === 'EXM' && ! array_key_exists('_vat', $b['items'][0]) && $b['transactionDate'] === '2026-10-23T10:15:00Z';
    });
});

it('reports a return as a partial refund of the original receipt, from the selling company’s own GRA account', function () {
    Http::fake(['vsdcstaging.vat-gh.com/*' => Http::response($this->ok)]);
    app(GraEvatAdapter::class)->submit(['type' => 'REFUND', 'number' => 'HQ-T01A-000130', 'original_number' => 'HQ-T01A-000123',
        'seller' => ['tin' => 'C0022222222', 'name' => 'Asante Pharmacy Ltd', 'branch' => 'HQ']] + $this->doc);
    Http::assertSent(fn (HttpRequest $req) => str_contains($req->url(), '/taxpayer/C0022222222-001/invoice') && $req->hasHeader('security_key', 'pharmacy-key=')
        && $req['flag'] === 'PARTIAL_REFUND' && $req['invoiceNumber'] === 'HQ-T01A-000123' && $req['reference'] === 'HQ-T01A-000130');
});

it('tells a refusal from GRA being down, and checks GRA’s health', function () {
    Http::fake(['vsdcstaging.vat-gh.com/*/invoice' => Http::sequence()
        ->push(['response' => ['status' => 'ERROR', 'message' => 'INVALID TIN']])
        ->push([], 403)
        ->push(['response' => ['status' => 'SUCCESS', 'message' => 'NUM: HQ-T01A-000123 ALREADY EXISTS']]),
        'vsdcstaging.vat-gh.com/*/health' => Http::response(['status' => 'UP'])]);
    $gra = app(GraEvatAdapter::class);
    expect(fn () => $gra->submit($this->doc))->toThrow(fn (DomainException $e) => expect($e->errorCode)->toBe('fiscal_rejected')->and($e->getMessage())->toContain('INVALID TIN'));
    expect(fn () => $gra->submit($this->doc))->toThrow(fn (DomainException $e) => expect($e->errorCode)->toBe('fiscal_unreachable'));
    expect($gra->submit($this->doc)['reference'])->toBe('HQ-T01A-000123') // GRA already had it
        ->and($gra->health())->toBeTrue();
});
