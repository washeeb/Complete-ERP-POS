<?php

namespace App\Domain\Fiscal;

use App\Domain\Channels\OutboxConsumers;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\OutboxEvent;
use App\Models\Sale;
use Throwable;

/**
 * Picks up committed sales, invoices and credit notes for GRA E-VAT (per the company's mode) and retries what is due.
 * Runs in the relay loop. Till sales arriving from offline tills are reported here too.
 */
final class FiscalRelay
{
    public function __construct(private readonly OutboxConsumers $consumers, private readonly FiscalService $fiscal) {}

    public function run(): int
    {
        $n = $this->consumers->consume('fiscal', function (OutboxEvent $e) {
            try {
                match (true) {
                    $e->aggregate_type === 'sale' => ($s = Sale::withoutGlobalScopes()->find($e->aggregate_id)) && $s->status === 'posted' ? $this->fiscal->recordSale($s) : null,
                    $e->event_type === 'invoice.posted' => ($i = Invoice::withoutGlobalScopes()->find($e->aggregate_id)) ? $this->fiscal->recordInvoice($i) : null,
                    $e->event_type === 'credit_note.issued' => ($c = CreditNote::withoutGlobalScopes()->find($e->aggregate_id)) ? $this->fiscal->recordInvoice($c) : null,
                    default => null,
                };
            } catch (Throwable $ex) {
                report($ex);
            }
        }, 500, fn (OutboxEvent $e) => ($e->aggregate_type === 'sale' && in_array($e->event_type, ['sale.posted', 'sale.posted_offline', 'sale.returned'], true))
            || in_array($e->event_type, ['invoice.posted', 'credit_note.issued'], true));

        return $n + $this->fiscal->submitDue();
    }
}
