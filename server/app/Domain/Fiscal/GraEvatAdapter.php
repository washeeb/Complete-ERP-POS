<?php

namespace App\Domain\Fiscal;

use App\Domain\Support\DomainException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * GRA E-VAT through GRA's Virtual Sales Data Controller (VSDC), "GRA E-VAT API VER 8.2" (published 2025-08):
 *   POST {GRA_EVAT_URL}/api/v1/taxpayer/{GRA_EVAT_COMPANY_REFERENCE}/invoice   header security_key: {GRA_EVAT_KEY}
 *   GET  {GRA_EVAT_URL}/api/v1/taxpayer/{reference}/health                      → {"status": "UP"}
 * e.g. GRA_EVAT_URL=https://vsdcstaging.vat-gh.com/vsdc (GRA's staging VSDC; GRA gives the production address, the
 * company reference such as C00XXXXXXXX-001 and the security key when the business is onboarded).
 *
 * A sale is flag INVOICE; a return is PARTIAL_REFUND against the original receipt (invoiceNumber = the original,
 * reference = the return), and so is a credit note against its invoice. Levies go per item: A = NHIL, B = GETFund,
 * C = COVID-19 (0 from 1 Jan 2026), D = CST, E = tourism. GRA signs each document: the SDC ID, receipt number, internal
 * data, signature, MRC and time, plus a QR code address — all printed on the receipt.
 */
final class GraEvatAdapter implements FiscalAdapter
{
    /** Our tax codes → GRA's levy letters (VAT is reported separately as totalVat). */
    public const LEVIES = ['NHIL' => 'A', 'GETFUND' => 'B', 'GETFL' => 'B', 'COVID' => 'C', 'CST' => 'D', 'TOURISM' => 'E', 'TRSM' => 'E'];

    public function submit(array $document): array
    {
        $res = $this->send('invoice', $this->payload($document), (string) ($document['seller']['tin'] ?? ''));
        $r = (array) ($res['response'] ?? $res);
        $status = strtoupper((string) ($r['status'] ?? $r['STATUS'] ?? ''));
        $msg = $r['mesaage'] ?? $r['message'] ?? $r['MESSAGE'] ?? null; // GRA's own examples spell it both ways
        if ($status !== 'SUCCESS') {
            $text = is_array($msg) ? json_encode($msg) : (string) ($msg ?? 'No status in the answer');
            throw new DomainException('GRA refused the document: '.Str::limit($text, 200), 'fiscal_rejected');
        }
        if (! is_array($msg)) {
            // "… ALREADY EXISTS": GRA has it from an earlier attempt whose answer was lost; it is reported.
            return ['reference' => $document['number'], 'qr' => null, 'raw' => $res];
        }
        $reference = trim(($msg['ysdcid'] ?? '').' '.($msg['ysdcrecnum'] ?? '')) ?: (string) ($msg['num'] ?? $document['number']);

        return ['reference' => $reference, 'qr' => isset($r['qr_code']) && $r['qr_code'] !== '' ? (string) $r['qr_code'] : (isset($msg['qr_code']) ? (string) $msg['qr_code'] : null), 'raw' => $res];
    }

    /**
     * GRA's signature block from a stored answer, as printed on the receipt (null for other answers).
     *
     * @return array{sdc_id: string, receipt: string, internal: string, signature: string, mrc: string, time: string}|null
     */
    public static function signature(?array $raw): ?array
    {
        $r = (array) ($raw['response'] ?? []);
        $m = $r['mesaage'] ?? $r['message'] ?? null;
        if (! is_array($m) || empty($m['ysdcregsig'])) {
            return null;
        }

        return ['sdc_id' => (string) ($m['ysdcid'] ?? ''), 'receipt' => (string) ($m['ysdcrecnum'] ?? ''), 'internal' => (string) ($m['ysdcintdata'] ?? ''),
            'signature' => (string) $m['ysdcregsig'], 'mrc' => (string) ($m['ysdcmrc'] ?? ''), 'time' => (string) ($m['ysdctime'] ?? '')];
    }

    /** GRA's VSDC takes one document per call: the daily batch sends them one after another. */
    public function submitBatch(array $documents): array
    {
        $out = [];
        foreach ($documents as $doc) {
            try {
                $r = $this->submit($doc);
                $out[] = ['ok' => true, 'reference' => $r['reference'], 'qr' => $r['qr'], 'error' => null, 'raw' => $r['raw']];
            } catch (DomainException $e) {
                if ($e->errorCode !== 'fiscal_rejected') {
                    throw $e; // GRA down: the whole batch waits for the next run
                }
                $out[] = ['ok' => false, 'reference' => null, 'qr' => null, 'error' => $e->getMessage(), 'raw' => []];
            }
        }

        return $out;
    }

    /** GRA's health check, for System → Health. */
    public function health(): bool
    {
        try {
            return strtoupper((string) ($this->request()->get($this->url('health'))->json('status') ?? '')) === 'UP';
        } catch (Throwable) {
            return false;
        }
    }

    public function payload(array $d): array
    {
        $refund = in_array($d['type'], ['REFUND', 'CREDIT_NOTE'], true);
        $items = array_map(function ($i) {
            $levy = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0, 'D' => 0.0, 'E' => 0.0];
            $vat = 0.0;
            foreach ($i['taxes'] as $t) {
                $code = strtoupper((string) $t['code']);
                isset(self::LEVIES[$code]) ? $levy[self::LEVIES[$code]] += (float) $t['amount'] : $vat += (float) $t['amount'];
            }

            return ['itemCode' => (string) ($i['code'] ?? ''), 'itemCategory' => $i['taxes'] ? '' : 'EXM', 'expireDate' => '', 'description' => Str::limit((string) $i['description'], 200, ''),
                'quantity' => round((float) $i['qty'], 3), 'levyAmountA' => round($levy['A'], 2), 'levyAmountB' => round($levy['B'], 2), 'levyAmountC' => round($levy['C'], 2),
                'levyAmountD' => round($levy['D'], 2), 'levyAmountE' => round($levy['E'], 2), 'discountAmount' => round((float) $i['discount'], 2), 'exciseAmount' => 0.0,
                'batchCode' => '', 'unitPrice' => round((float) $i['unit_price'], 4), '_vat' => $vat];
        }, $d['items']);
        $totalVat = array_sum(array_column($items, '_vat'));
        $totalLevy = array_sum(array_map(fn ($i) => $i['levyAmountA'] + $i['levyAmountB'] + $i['levyAmountC'] + $i['levyAmountD'] + $i['levyAmountE'], $items));

        return [
            'currency' => $d['currency'], 'exchangeRate' => 1.0,
            'invoiceNumber' => $refund ? (string) ($d['original_number'] ?: $d['number']) : $d['number'],
            'totalLevy' => round($totalLevy, 2), 'userName' => Str::limit((string) ($d['user'] ?? 'System'), 60, ''),
            'flag' => $refund ? 'PARTIAL_REFUND' : 'INVOICE', 'calculationType' => $d['prices_include_tax'] ? 'INCLUSIVE' : 'EXCLUSIVE',
            'totalVat' => round($totalVat, 2), 'transactionDate' => $d['datetime'] ?? ($d['date'].'T00:00:00Z'), 'totalAmount' => round((float) $d['total'], 2),
            'totalExciseAmount' => 0.0, 'voucherAmount' => 0.0,
            'businessPartnerName' => (string) ($d['buyer']['name'] ?: 'Cash customer'), 'businessPartnerTin' => (string) ($d['buyer']['tin'] ?: 'C0000000000'),
            'saleType' => 'NORMAL', 'discountType' => 'GENERAL', 'discountAmount' => round((float) ($d['discount'] ?? 0), 2),
            'reference' => $refund ? $d['number'] : '', 'groupReferenceId' => '', 'purchaseOrderReference' => '',
            'items' => array_map(fn ($i) => array_diff_key($i, ['_vat' => 0]), $items),
        ];
    }

    private function send(string $path, array $body, string $tin = ''): array
    {
        try {
            $res = $this->request($tin)->post($this->url($path, $tin), $body);
        } catch (DomainException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new DomainException('GRA E-VAT not reachable: '.Str::limit($e->getMessage(), 150), 'fiscal_unreachable');
        }

        return $this->read($res);
    }

    private function read(Response $res): array
    {
        if (in_array($res->status(), [400, 422], true)) {
            throw new DomainException('GRA refused the document: '.Str::limit((string) ($res->json('response.message') ?? $res->json('message') ?? $res->body()), 200), 'fiscal_rejected');
        }
        if (in_array($res->status(), [401, 403], true)) {
            // Wrong key or company reference: nothing is lost, the documents wait until the .env is corrected.
            throw new DomainException('GRA did not accept this server’s E-VAT key or company reference (GRA_EVAT_KEY, GRA_EVAT_COMPANY_REFERENCE).', 'fiscal_unreachable');
        }
        if (! $res->successful()) {
            throw new DomainException("GRA E-VAT answered {$res->status()}.", 'fiscal_unreachable');
        }

        return (array) $res->json();
    }

    /**
     * The GRA account of the selling company: separate legal entities (separate books) each have their own TIN, company
     * reference and key (GRA_EVAT_ACCOUNTS); otherwise the server's one account.
     *
     * @return array{reference: string, key: string}
     */
    public static function account(string $tin = ''): array
    {
        $accounts = (array) config('fiscal.gra.accounts', []);
        $a = $tin !== '' && isset($accounts[$tin]) ? (array) $accounts[$tin] : ['reference' => config('fiscal.gra.reference'), 'key' => config('fiscal.gra.key')];

        return ['reference' => (string) ($a['reference'] ?? ''), 'key' => (string) ($a['key'] ?? '')];
    }

    private function request(string $tin = '')
    {
        $a = self::account($tin);
        if ((string) config('fiscal.gra.url') === '' || $a['key'] === '' || $a['reference'] === '') {
            throw new DomainException('GRA E-VAT is not set up on this server (.env: GRA_EVAT_URL, GRA_EVAT_COMPANY_REFERENCE and GRA_EVAT_KEY).', 'fiscal_unreachable');
        }

        return Http::withHeaders(['security_key' => $a['key']])->acceptJson()->asJson()->timeout((int) config('fiscal.gra.timeout', 8));
    }

    private function url(string $path, string $tin = ''): string
    {
        return rtrim((string) config('fiscal.gra.url'), '/').'/api/v1/taxpayer/'.rawurlencode(self::account($tin)['reference']).'/'.$path;
    }
}
