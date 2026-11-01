<?php

namespace App\Domain\Purchasing;

use App\Domain\Messaging\Notifier;
use App\Domain\Payables\SupplierLedger;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Http\Controllers\PrintController;
use App\Models\PurchaseOrder;
use App\Models\RfqSupplier;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\Tenant;
use App\Security\Authorizer;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The supplier portal (procurement stage 3): one secret link per supplier, no account or password. On it the supplier
 * sees the company's requests for quotation to them, confirms purchase orders with the date they will deliver, says
 * when goods are on the way (their delivery note number), sends invoices (a PDF or photo), and sees which bills are
 * still open and which payments were made, with withholding tax certificates. Off until switched on (Settings →
 * Purchasing); the buyer can make a new link at any time, which stops the old one.
 */
final class SupplierPortal
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly Settings $settings,
        private readonly Notifier $notifier,
        private readonly SupplierMessages $messages,
        private readonly SupplierLedger $ledger,
    ) {}

    public function enabled(string $tenantId): bool
    {
        return (bool) $this->settings->get('purchasing.supplier_portal', $tenantId);
    }

    /** The supplier behind a portal link, while the portal is on and the supplier is not blocked. */
    public function supplierFor(string $token): ?Supplier
    {
        if (strlen($token) !== 40) {
            return null;
        }
        $s = Supplier::withoutGlobalScopes()->where('portal_token', $token)->first();

        return $s && $s->status !== 'blocked' && $this->enabled($s->tenant_id) ? $s : null;
    }

    /** The supplier's link, made the first time; $renew makes a new one and the old one stops working. */
    public function link(Actor $actor, Supplier $s, bool $renew = false): string
    {
        $this->require($actor, 'purchasing.manage');
        if (! $this->enabled($s->tenant_id)) {
            throw new DomainException('The supplier portal is off. Switch it on under System → Settings → Purchasing.');
        }
        if ($s->status === 'blocked') {
            throw new DomainException("{$s->name} is blocked.");
        }
        if ($s->portal_token && ! $renew) {
            return route('supplier.portal', $s->portal_token);
        }
        $token = Str::random(40);
        $this->recorder->transaction(function () use ($actor, $s, $token, $renew) {
            // Straight to the row: the secret never goes into the audit trail or the sync snapshots.
            DB::table('suppliers')->where('id', $s->id)->update(['portal_token' => $token, 'updated_at' => now()]);
            $this->recorder->audit->record($actor, 'supplier.portal_link', $s, [], $renew ? 'New link: the old one no longer works' : 'Portal link made');
        });
        $s->portal_token = $token;
        $s->syncOriginalAttribute('portal_token');

        return route('supplier.portal', $token);
    }

    /** @return ?string the WhatsApp address to open on this computer (whatsapp_link) */
    public function send(Actor $actor, Supplier $s, string $channel, string $to): ?string
    {
        $link = $this->link($actor, $s);
        $to = trim($to);
        $vars = ['company' => PrintController::companyOf($s->tenant_id, null)['name'], 'supplier' => $s->name, 'link' => $link, 'note' => ''];
        $open = $this->messages->send($s->tenant_id, 'supplier_portal', $channel, $to, $vars, $s->code ?? $s->name, $actor->name);
        $this->recorder->transaction(function () use ($actor, $s, $channel, $to) {
            DB::table('suppliers')->where('id', $s->id)->update(['portal_sent_at' => now()]);
            $this->recorder->audit->record($actor, 'supplier.portal_sent', $s, [], OrderSender::CHANNELS[$channel]." to {$to}");
        });

        return $open;
    }

    /** Orders the supplier may see: sent to them (approved) in the last six months. */
    public function orders(Supplier $s): Collection
    {
        return PurchaseOrder::withoutGlobalScopes()->where('supplier_id', $s->id)->whereIn('status', ['approved', 'partially_received', 'received', 'closed'])
            ->where('approved_at', '>=', now()->subMonths(6))->orderByDesc('approved_at')->get();
    }

    public function order(Supplier $s, string $id): ?PurchaseOrder
    {
        return $this->orders($s)->firstWhere('id', $id)?->load('lines');
    }

    /** Requests for quotation to this supplier that are open, or decided in the last 30 days. */
    public function rfqs(Supplier $s): Collection
    {
        return RfqSupplier::with('rfq')->where('supplier_id', $s->id)->get()
            ->filter(fn (RfqSupplier $i) => $i->rfq->acceptsQuotes() || ($i->rfq->updated_at && $i->rfq->updated_at->isAfter(now()->subDays(30))))
            ->sortByDesc(fn (RfqSupplier $i) => $i->rfq->closes_at)->values();
    }

    /** The supplier confirms an order and the date they will deliver. */
    public function confirm(Supplier $s, PurchaseOrder $po, string $promisedOn, ?string $reply, string $byName): PurchaseOrder
    {
        $this->own($s, $po, ['approved', 'partially_received']);
        $day = Carbon::parse($promisedOn);
        if ($day->isBefore(today())) {
            throw new DomainException('The delivery date cannot be in the past.');
        }
        $actor = $this->supplierActor($s, $byName);
        $po->forceFill(['supplier_confirmed_at' => now(), 'supplier_promised_on' => $day->toDateString(), 'supplier_reply' => ($reply = trim((string) $reply)) !== '' ? mb_substr($reply, 0, 500) : null]);

        return $this->recorder->transaction(function () use ($actor, $po, $s, $day) {
            $this->recorder->save($actor, $po, 'purchase_order.supplier_confirmed', "Delivery promised for {$day->format('j M Y')}", $po->branch_id);
            $this->notifier->toPermission($po->tenant_id, 'purchasing.manage', $po->branch_id, "{$s->name} confirmed {$po->number}",
                "Delivery promised for {$day->format('j M Y')}".($po->supplier_reply ? ": {$po->supplier_reply}" : '.'), url('/admin/purchase-orders/'.$po->id));

            return $po;
        });
    }

    /** The goods are on the way: the store is told to expect them. */
    public function dispatch(Supplier $s, PurchaseOrder $po, string $reference, string $byName): PurchaseOrder
    {
        $this->own($s, $po, ['approved', 'partially_received']);
        $reference = trim($reference);
        if ($reference === '') {
            throw new DomainException('Give your delivery note or waybill number.');
        }
        $actor = $this->supplierActor($s, $byName);
        $po->forceFill(['dispatched_at' => now(), 'dispatch_ref' => mb_substr($reference, 0, 120)]);

        return $this->recorder->transaction(function () use ($actor, $po, $s) {
            $this->recorder->save($actor, $po, 'purchase_order.dispatched', $po->dispatch_ref, $po->branch_id);
            $this->notifier->toPermission($po->tenant_id, ['stock.receive', 'purchasing.manage'], $po->branch_id, "Goods on the way: {$po->number} from {$s->name}",
                "Their delivery note: {$po->dispatch_ref}. Receive them against {$po->number}.", url('/admin/purchase-orders/'.$po->id));

            return $po;
        });
    }

    /** @param array{invoice_no: string, invoice_date: string, amount: string, purchase_order_id?: ?string, note?: ?string} $data */
    public function submitInvoice(Supplier $s, array $data, ?string $path, ?string $fileName, string $byName): SupplierInvoice
    {
        $no = trim((string) ($data['invoice_no'] ?? ''));
        if ($no === '') {
            throw new DomainException('Give your invoice number.');
        }
        $currency = Tenant::find($s->tenant_id)->currency_code;
        $amount = Money::toMinor((string) ($data['amount'] ?? '0'), $currency);
        if ($amount <= 0) {
            throw new DomainException('Give the invoice total (with VAT).');
        }
        $date = Carbon::parse($data['invoice_date'] ?? 'now');
        if ($date->isAfter(today())) {
            throw new DomainException('The invoice date cannot be in the future.');
        }
        $po = ($data['purchase_order_id'] ?? null) ? $this->order($s, $data['purchase_order_id']) ?? throw new DomainException('That order is not one of yours.') : null;
        $dup = SupplierInvoice::withoutGlobalScopes()->where('supplier_id', $s->id)->whereRaw('lower(invoice_no) = ?', [mb_strtolower($no)])->where('status', '!=', 'rejected')->exists()
            || SupplierBill::withoutGlobalScopes()->where('supplier_id', $s->id)->whereRaw('lower(supplier_invoice_no) = ?', [mb_strtolower($no)])->where('status', '!=', 'rejected')->exists();
        if ($dup) {
            throw new DomainException("Invoice {$no} has already been sent to us.");
        }
        $actor = $this->supplierActor($s, $byName);

        return $this->recorder->transaction(function () use ($actor, $s, $no, $date, $amount, $currency, $po, $path, $fileName, $data, $byName) {
            $inv = (new SupplierInvoice)->forceFill(['tenant_id' => $s->tenant_id, 'supplier_id' => $s->id, 'purchase_order_id' => $po?->id, 'invoice_no' => mb_substr($no, 0, 60),
                'invoice_date' => $date->toDateString(), 'amount_minor' => $amount, 'currency_code' => $currency, 'file_path' => $path,
                'file_name' => $fileName ? mb_substr($fileName, 0, 255) : null, 'note' => ($data['note'] ?? null) ? mb_substr(trim($data['note']), 0, 500) : null,
                'sent_by_name' => mb_substr(trim($byName), 0, 120), 'status' => 'received']);
            $this->recorder->save($actor, $inv, 'supplier_invoice.received', $po ? "For {$po->number}" : null, $po?->branch_id);
            $this->notifier->toPermission($s->tenant_id, 'payables.manage', $po?->branch_id, "Invoice {$no} from {$s->name}",
                Money::display($amount, $currency).($po ? " for {$po->number}" : '').'. Enter it as a supplier bill: Purchasing → Invoices from suppliers.', url('/admin/supplier-invoices'));

            return $inv;
        });
    }

    /** Payables entered it as a bill (or found it already entered). */
    public function markEntered(Actor $actor, SupplierInvoice $inv, ?string $billId): SupplierInvoice
    {
        $this->require($actor, 'payables.manage');
        $this->waiting($inv);
        $bill = $billId ? SupplierBill::where('supplier_id', $inv->supplier_id)->findOrFail($billId) : null;
        $inv->forceFill(['status' => 'entered', 'supplier_bill_id' => $bill?->id, 'handled_by_name' => $actor->name, 'handled_at' => now()]);

        return $this->recorder->save($actor, $inv, 'supplier_invoice.entered', $bill?->number);
    }

    /** Turned back to the supplier with the reason they see on the portal (wrong prices, no order, duplicate…). */
    public function reject(Actor $actor, SupplierInvoice $inv, string $reason): SupplierInvoice
    {
        $this->require($actor, 'payables.manage');
        $this->waiting($inv);
        if (trim($reason) === '') {
            throw new DomainException('Give the reason; the supplier sees it.');
        }
        $inv->forceFill(['status' => 'rejected', 'reject_reason' => mb_substr(trim($reason), 0, 255), 'handled_by_name' => $actor->name, 'handled_at' => now()]);

        return $this->recorder->save($actor, $inv, 'supplier_invoice.rejected', $reason);
    }

    /**
     * The supplier's account as they see it: bills still open, payments of the last twelve months (with the tax
     * kept back and the certificate), and the invoices they sent through the portal.
     *
     * @return array{open: list<object>, owed: int, payments: Collection, invoices: Collection, currency: string}
     */
    public function statement(Supplier $s): array
    {
        $open = $this->ledger->openItems($s->id);
        $refs = SupplierBill::withoutGlobalScopes()->whereIn('id', array_column(array_filter($open, fn ($o) => $o->doc_type === 'supplier_bill'), 'doc_id'))
            ->pluck('supplier_invoice_no', 'id');
        foreach ($open as $o) {
            $o->their_ref = $refs[$o->doc_id] ?? null;
        }

        return ['open' => $open, 'owed' => array_sum(array_map(fn ($o) => (int) $o->open_minor, $open)) - $this->ledger->unallocated($s->id),
            'payments' => SupplierPayment::withoutGlobalScopes()->where('supplier_id', $s->id)->where('paid_on', '>=', now()->subMonths(12)->toDateString())->orderByDesc('paid_on')->get(),
            'invoices' => SupplierInvoice::withoutGlobalScopes()->where('supplier_id', $s->id)->where('created_at', '>=', now()->subMonths(6))->orderByDesc('created_at')->get(),
            'currency' => Tenant::find($s->tenant_id)->currency_code];
    }

    private function own(Supplier $s, PurchaseOrder $po, array $statuses): void
    {
        if ($po->supplier_id !== $s->id) {
            throw new DomainException('That order is not one of yours.');
        }
        if (! in_array($po->status, $statuses, true)) {
            throw new DomainException("Order {$po->number} is ".strtolower(PurchaseOrder::STATUSES[$po->status] ?? $po->status).'.');
        }
    }

    private function waiting(SupplierInvoice $inv): void
    {
        if ($inv->status !== 'received') {
            throw new DomainException("Invoice {$inv->invoice_no} is already ".strtolower(SupplierInvoice::STATUSES[$inv->status]).'.');
        }
    }

    private function supplierActor(Supplier $s, string $byName): Actor
    {
        $byName = trim($byName);
        if ($byName === '') {
            throw new DomainException('Give your name.');
        }

        return Actor::system($s->tenant_id, mb_substr("{$byName} ({$s->name}, supplier portal)", 0, 160));
    }

    private function require(Actor $actor, string $permission): void
    {
        if (! $actor->user || ! $this->authz->allowsAnywhere($actor->user, $permission)) {
            throw new AccessDenied;
        }
    }
}
