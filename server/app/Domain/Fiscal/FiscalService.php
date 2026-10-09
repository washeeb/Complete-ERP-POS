<?php

namespace App\Domain\Fiscal;

use App\Domain\Support\DomainException;
use App\Models\Branch;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\FiscalSubmission;
use App\Models\Invoice;
use App\Models\Sale;
use App\Models\Tenant;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * GRA E-VAT reporting with the three modes the owner asked for (2026-10-29), chosen under System → Settings → E-VAT:
 *
 * - **realtime** — each till sale and invoice is sent as it happens; the till waits a few seconds for GRA's code and
 *   QR to print on the receipt, and sends it later (retried) when GRA or the internet is not there;
 * - **daily** — till sales are sent together once a day (receipts carry no GRA code); invoices still go one by one;
 * - **invoices** — only customer invoices and credit notes are reported, not till sales.
 *
 * Every document is kept in fiscal_submissions with GRA's answer; failures are retried with back-off; a document GRA
 * refuses is marked "rejected" for someone to look at.
 */
final class FiscalService
{
    public const MODES = ['off' => 'Off', 'realtime' => 'Each sale and invoice as it happens', 'daily' => 'Till sales in a daily batch, invoices as they happen',
        'invoices' => 'Invoices and credit notes only'];

    private const BACKOFF = [1, 5, 15, 60, 180, 360];

    public function __construct(private readonly Settings $settings) {}

    public function adapter(): FiscalAdapter
    {
        return app()->bound('fiscal.fake') ? app('fiscal.fake') : app(GraEvatAdapter::class);
    }

    public function mode(string $tenantId): string
    {
        return (string) $this->settings->get('evat.mode', $tenantId);
    }

    /** A till sale or return to report (per the mode); null when this mode does not report till sales. */
    public function recordSale(Sale $sale): ?FiscalSubmission
    {
        $mode = $this->mode($sale->tenant_id);
        if (! in_array($mode, ['realtime', 'daily'], true)) {
            return null;
        }

        return $this->record($sale->tenant_id, 'sale', $sale->id, $sale->receipt_no, $sale->branch_id, $sale->occurred_at, $mode);
    }

    public function recordInvoice(Invoice|CreditNote $doc): ?FiscalSubmission
    {
        $mode = $this->mode($doc->tenant_id);
        if ($mode === 'off') {
            return null;
        }

        return $this->record($doc->tenant_id, $doc instanceof Invoice ? 'invoice' : 'credit_note', $doc->id, $doc->number, $doc->branch_id,
            $doc instanceof Invoice ? $doc->issued_on : $doc->created_at, $mode === 'daily' ? 'realtime' : $mode);
    }

    /** Realtime mode at the till: record and try once now, within GRA_EVAT_TIMEOUT seconds. */
    public function submitSaleNow(Sale $sale): ?FiscalSubmission
    {
        if ($this->mode($sale->tenant_id) !== 'realtime') {
            return null;
        }
        $sub = $this->recordSale($sale);
        if ($sub && $sub->status === 'pending') {
            $this->submit($sub);
        }

        return $sub?->fresh();
    }

    /** Send one document now. */
    public function submit(FiscalSubmission $sub): FiscalSubmission
    {
        $doc = $this->document($sub);
        $sub->attempts++;
        try {
            $r = $this->adapter()->submit($doc);
            $sub->forceFill(['status' => 'submitted', 'gra_reference' => mb_substr($r['reference'], 0, 120), 'qr_code' => $r['qr'], 'payload' => $doc, 'response' => $r['raw'],
                'error' => null, 'next_attempt_at' => null])->save();
        } catch (DomainException $e) {
            $this->failed($sub, $doc, $e->getMessage(), $e->errorCode === 'fiscal_rejected');
        } catch (Throwable $e) {
            $this->failed($sub, $doc, $e->getMessage(), false);
        }

        return $sub;
    }

    /** Retry what is due (realtime and invoices); run by the relay. */
    public function submitDue(int $limit = 50): int
    {
        $n = 0;
        foreach (FiscalSubmission::withoutGlobalScopes()->whereIn('mode', ['realtime', 'invoices'])->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('created_at')->limit($limit)->get() as $sub) {
            if ($this->mode($sub->tenant_id) !== 'off') {
                $this->submit($sub);
                $n++;
            }
        }

        return $n;
    }

    /** Daily mode: send the till sales still waiting, up to and including $date, in batches of 100. @return int sent */
    public function batch(string $tenantId, ?string $date = null): int
    {
        $tz = Tenant::find($tenantId)?->timezone ?: 'UTC';
        $date ??= CarbonImmutable::now($tz)->toDateString();
        $sent = 0;
        FiscalSubmission::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('mode', 'daily')->where('status', 'pending')->where('doc_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('doc_date')->orderBy('doc_number')
            ->chunkById(100, function ($subs) use (&$sent) {
                $docs = $subs->map(fn ($s) => $this->document($s))->all();
                $batchId = 'B'.now()->format('YmdHis').'-'.Str::lower(Str::random(4));
                try {
                    $results = $this->adapter()->submitBatch($docs);
                } catch (Throwable $e) {
                    foreach ($subs as $i => $s) {
                        $s->attempts++;
                        $this->failed($s, $docs[$i], $e->getMessage(), false);
                    }

                    return;
                }
                foreach ($subs->values() as $i => $s) {
                    $r = $results[$i] ?? ['ok' => false, 'error' => 'No answer', 'raw' => []];
                    $s->attempts++;
                    if ($r['ok']) {
                        $s->forceFill(['status' => 'submitted', 'batch_id' => $batchId, 'gra_reference' => $r['reference'], 'qr_code' => $r['qr'], 'payload' => $docs[$i],
                            'response' => $r['raw'], 'error' => null, 'next_attempt_at' => null])->save();
                        $sent++;
                    } else {
                        $this->failed($s, $docs[$i], (string) $r['error'], true);
                    }
                }
            });

        return $sent;
    }

    /** The document as sent to GRA. */
    public function document(FiscalSubmission $sub): array
    {
        $tenant = Tenant::findOrFail($sub->tenant_id);
        $branch = Branch::withoutGlobalScopes()->with('legalEntity')->find($sub->branch_id);
        $entity = $branch?->legalEntity;
        $dp = Money::exponent($tenant->currency_code);
        $amt = fn ($minor) => round(((int) $minor) / 10 ** $dp, $dp);
        [$model, $lines, $type, $original, $customerId] = match ($sub->doc_type) {
            'sale' => (function () use ($sub) {
                $s = Sale::withoutGlobalScopes()->with('lines')->findOrFail($sub->doc_id);

                return [$s, $s->lines, $s->kind === 'return' ? 'REFUND' : 'INVOICE', $s->original_sale_id ? Sale::withoutGlobalScopes()->whereKey($s->original_sale_id)->value('receipt_no') : null, $s->customer_id];
            })(),
            'invoice' => (function () use ($sub) {
                $i = Invoice::withoutGlobalScopes()->findOrFail($sub->doc_id);

                return [$i, DB::table('invoice_lines')->where('invoice_id', $i->id)->orderBy('line_no')->get(), 'INVOICE', null, $i->customer_id];
            })(),
            default => (function () use ($sub) {
                $c = CreditNote::withoutGlobalScopes()->findOrFail($sub->doc_id);
                $inv = Invoice::withoutGlobalScopes()->find($c->invoice_id);
                // A credit note is one amount against an invoice: its tax is split like the invoice's.
                $ratio = $inv && $inv->tax_minor ? $c->tax_minor / $inv->tax_minor : 0;
                $c->tax_breakdown = array_map(fn ($t) => ['amount_minor' => (int) round($t['amount_minor'] * $ratio)] + $t, $inv?->tax_breakdown ?? []);
                $line = (object) ['sku' => null, 'name' => 'Credit on invoice '.$inv?->number.($c->reason ? ": {$c->reason}" : ''), 'qty' => 1, 'unit_price_minor' => $c->net_minor,
                    'net_minor' => $c->net_minor, 'tax_breakdown' => $c->tax_breakdown, 'line_total_minor' => $c->total_minor];

                return [$c, [$line], 'CREDIT_NOTE', $inv?->number, $c->customer_id];
            })(),
        };
        $customer = $customerId ? Customer::withoutGlobalScopes()->find($customerId) : null;
        $breakdown = fn ($b) => array_map(fn ($t) => ['code' => $t['code'], 'rate' => round(($t['rate_bp'] ?? 0) / 100, 2), 'amount' => $amt($t['amount_minor'])],
            is_string($b) ? (json_decode($b, true) ?: []) : (array) ($b ?? []));

        return [
            'type' => $type, 'number' => $sub->doc_number, 'original_number' => $original, 'date' => CarbonImmutable::parse($sub->doc_date)->toDateString(),
            'currency' => $tenant->currency_code, 'prices_include_tax' => (bool) ($model->prices_include_tax ?? false),
            'seller' => ['tin' => $entity?->tin ?: (string) config('fiscal.gra.tin'), 'name' => $entity?->name ?: $tenant->name, 'branch' => $branch?->code],
            'buyer' => ['tin' => $customer?->tax_no, 'name' => $customer?->name, 'phone' => $customer?->phone],
            'items' => collect($lines)->map(fn ($l) => ['code' => $l->sku ?? null, 'description' => $l->name ?? $l->description ?? '', 'qty' => abs((float) $l->qty),
                'unit_price' => $amt($l->unit_price_minor ?? $l->unit_cost_minor ?? 0), 'discount' => $amt($l->discount_minor ?? 0), 'net' => $amt(abs((int) $l->net_minor)),
                'taxes' => array_map(fn ($t) => ['amount' => abs($t['amount'])] + $t, $breakdown($l->tax_breakdown ?? [])),
                'total' => $amt(abs((int) ($l->line_total_minor ?? $l->total_minor ?? 0)))])->values()->all(),
            'net' => $amt(abs((int) $model->net_minor)), 'taxes' => array_map(fn ($t) => ['amount' => abs($t['amount'])] + $t, $breakdown($model->tax_breakdown)),
            'total' => $amt(abs((int) $model->total_minor)),
        ];
    }

    private function record(string $tenantId, string $type, string $id, string $number, ?string $branchId, $date, string $mode): FiscalSubmission
    {
        return FiscalSubmission::withoutGlobalScopes()->firstOrCreate(['doc_type' => $type, 'doc_id' => $id], ['tenant_id' => $tenantId, 'doc_number' => $number,
            'branch_id' => $branchId, 'doc_date' => CarbonImmutable::parse($date)->setTimezone(Tenant::find($tenantId)?->timezone ?: 'UTC')->toDateString(),
            'mode' => $mode, 'status' => 'pending', 'next_attempt_at' => now()]);
    }

    private function failed(FiscalSubmission $sub, array $doc, string $error, bool $rejected): void
    {
        $sub->forceFill(['status' => $rejected ? 'rejected' : 'pending', 'payload' => $doc, 'error' => Str::limit($error, 250),
            'next_attempt_at' => $rejected ? null : now()->addMinutes(self::BACKOFF[min(max(0, $sub->attempts - 1), count(self::BACKOFF) - 1)])])->save();
    }
}
