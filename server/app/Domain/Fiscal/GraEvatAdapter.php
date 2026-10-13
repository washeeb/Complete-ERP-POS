<?php

namespace App\Domain\Fiscal;

use App\Domain\Support\DomainException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * GRA E-VAT (Ghana Revenue Authority electronic VAT invoicing). The address, the company's API key and the
 * environment (sandbox or production) come from the server's .env, issued by GRA when the business is onboarded.
 *
 * GRA publishes the field names to its onboarded taxpayers; this adapter sends the document in the structure GRA's
 * E-VAT integration uses (header, items with their VAT/NHIL/GETFund lines, totals) and reads GRA's answer tolerantly
 * (reference or signature number, QR content). Confirm the mapping against GRA's sandbox before going live
 * (docs/evat.md) — the rest of the system does not change if fields are renamed here.
 */
final class GraEvatAdapter implements FiscalAdapter
{
    public function submit(array $document): array
    {
        $res = $this->post('/invoices', $this->payload($document));

        return ['reference' => self::reference($res), 'qr' => self::qr($res), 'raw' => $res];
    }

    public function submitBatch(array $documents): array
    {
        $res = $this->post('/invoices/batch', ['invoices' => array_map([$this, 'payload'], $documents)]);
        $items = $res['results'] ?? $res['invoices'] ?? $res['data'] ?? [];
        $out = [];
        foreach (array_values($documents) as $i => $doc) {
            $r = (array) ($items[$i] ?? []);
            $ok = ! isset($r['error']) && ! isset($r['errors']) && self::reference($r) !== '';
            $out[] = ['ok' => $ok, 'reference' => $ok ? self::reference($r) : null, 'qr' => self::qr($r), 'error' => $ok ? null : Str::limit(json_encode($r['error'] ?? $r['errors'] ?? 'No answer for this invoice'), 200), 'raw' => $r];
        }

        return $out;
    }

    private function payload(array $d): array
    {
        return [
            'invoiceType' => $d['type'], 'invoiceNumber' => $d['number'], 'originalInvoiceNumber' => $d['original_number'],
            'invoiceDate' => $d['date'], 'currency' => $d['currency'], 'exchangeRate' => 1,
            'supplier' => ['tin' => $d['seller']['tin'], 'name' => $d['seller']['name'], 'branch' => $d['seller']['branch']],
            'buyer' => ['tin' => $d['buyer']['tin'], 'name' => $d['buyer']['name'] ?: 'Cash customer', 'phone' => $d['buyer']['phone']],
            'items' => array_map(fn ($i) => ['code' => $i['code'], 'description' => $i['description'], 'quantity' => $i['qty'], 'unitPrice' => $i['unit_price'],
                'discount' => $i['discount'], 'netAmount' => $i['net'], 'taxes' => array_map(fn ($t) => ['code' => $t['code'], 'rate' => $t['rate'], 'amount' => $t['amount']], $i['taxes']),
                'totalAmount' => $i['total']], $d['items']),
            'totals' => ['net' => $d['net'], 'taxes' => $d['taxes'], 'total' => $d['total']],
            'pricesIncludeTax' => $d['prices_include_tax'],
        ];
    }

    private function post(string $path, array $body): array
    {
        $base = rtrim((string) config('fiscal.gra.url'), '/');
        $key = (string) config('fiscal.gra.key');
        if ($base === '' || $key === '') {
            throw new DomainException('GRA E-VAT is not set up on this server (.env: GRA_EVAT_URL and GRA_EVAT_KEY).');
        }
        try {
            $res = Http::withHeaders(['Authorization' => "Bearer {$key}", 'X-Taxpayer-TIN' => (string) ($body['supplier']['tin'] ?? null ?: config('fiscal.gra.tin'))])->acceptJson() // the selling company's TIN (separate books)
                ->timeout((int) config('fiscal.gra.timeout', 8))->post($base.$path, $body);
        } catch (Throwable $e) {
            throw new DomainException('GRA E-VAT not reachable: '.Str::limit($e->getMessage(), 150), 'fiscal_unreachable');
        }
        if ($res->status() === 422 || $res->status() === 400) {
            throw new DomainException('GRA refused the invoice: '.Str::limit((string) ($res->json('message') ?? json_encode($res->json('errors')) ?? $res->body()), 200), 'fiscal_rejected');
        }
        if (! $res->successful()) {
            throw new DomainException("GRA E-VAT answered {$res->status()}.", 'fiscal_unreachable');
        }

        return (array) $res->json();
    }

    private static function reference(array $r): string
    {
        return (string) ($r['reference'] ?? $r['invoiceReference'] ?? $r['signature'] ?? $r['ysdcid'] ?? $r['sdcId'] ?? $r['invoice_number'] ?? $r['id'] ?? '');
    }

    private static function qr(array $r): ?string
    {
        $q = $r['qrCode'] ?? $r['qr_code'] ?? $r['qr'] ?? $r['verificationUrl'] ?? null;

        return $q === null ? null : (string) $q;
    }
}
