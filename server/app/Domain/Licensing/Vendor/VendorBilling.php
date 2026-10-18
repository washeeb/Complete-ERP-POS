<?php

namespace App\Domain\Licensing\Vendor;

use App\Domain\Support\Actor;
use App\Domain\Support\AuditLog;
use App\Domain\Support\DomainException;
use App\Models\Vendor\VendorCustomer;
use App\Models\Vendor\VendorInvoice;
use App\Models\Vendor\VendorLicense;
use App\Models\Vendor\VendorPayment;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** Invoices and payments for licences (SyncLytics only). Money in integer minor units; a paid invoice is never changed, only voided with a reason. */
final class VendorBilling
{
    public function __construct(private readonly AuditLog $audit) {}

    /** @param list<array{description: string, qty: int|float, unit_minor: int}> $lines */
    public function invoice(Actor $actor, VendorCustomer $customer, array $lines, string $currency, ?string $licenseId = null, int $dueDays = 14, ?string $notes = null): VendorInvoice
    {
        $rows = [];
        foreach ($lines as $l) {
            $qty = (float) ($l['qty'] ?? 1);
            if (trim((string) ($l['description'] ?? '')) === '' || $qty <= 0) {
                continue;
            }
            $rows[] = ['description' => mb_substr(trim($l['description']), 0, 255), 'qty' => $qty, 'unit_minor' => (int) $l['unit_minor'],
                'total_minor' => (int) round($qty * (int) $l['unit_minor'])];
        }
        if (! $rows) {
            throw new DomainException('Add at least one line.');
        }

        return DB::transaction(function () use ($actor, $customer, $rows, $currency, $licenseId, $dueDays, $notes) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['vendor_invoice_number']);
            $year = now()->format('Y');
            $n = 1 + (int) DB::table('vendor_invoices')->where('number', 'like', "SYN-{$year}-%")->count();
            $inv = VendorInvoice::create(['number' => sprintf('SYN-%s-%04d', $year, $n), 'customer_id' => $customer->id, 'license_id' => $licenseId,
                'issued_on' => now()->toDateString(), 'due_on' => now()->addDays(max(0, $dueDays))->toDateString(), 'lines' => $rows,
                'total_minor' => array_sum(array_column($rows, 'total_minor')), 'currency' => $currency, 'status' => 'open',
                'notes' => $notes ? mb_substr($notes, 0, 500) : null, 'created_by' => $actor->name]);
            $this->audit->record($actor, 'vendor.invoice.issued', $inv, ['total_minor' => [null, $inv->total_minor]]);

            return $inv;
        });
    }

    /** The invoice for a licence: the plan (or "Licence"), each add-on, for the licence period. */
    public function invoiceForLicense(Actor $actor, VendorLicense $lic, int $dueDays = 14): VendorInvoice
    {
        $period = $lic->starts_on?->format('j M Y').' – '.$lic->expires_on?->format('j M Y');
        $addonTotal = 0;
        $lines = [];
        foreach ($lic->addons ?? [] as $a) {
            $lines[] = ['description' => "{$a['name']} ({$period})", 'qty' => $a['qty'], 'unit_minor' => (int) $a['price_minor']];
            $addonTotal += $a['qty'] * (int) $a['price_minor'];
        }
        array_unshift($lines, ['description' => 'SES Labs licence'.($lic->plan ? " — {$lic->plan->name}" : '').($lic->site ? " ({$lic->site})" : '')." {$period}",
            'qty' => 1, 'unit_minor' => max(0, (int) $lic->price_minor - $addonTotal)]);

        return $this->invoice($actor, $lic->client, array_values(array_filter($lines, fn ($l) => $l['unit_minor'] > 0 || $l === $lines[0])), $lic->currency ?? 'GHS',
            $lic->id, $dueDays, 'Licence '.$lic->short());
    }

    public function pay(Actor $actor, VendorInvoice $inv, int $amountMinor, string $method, ?string $reference, ?string $receivedOn = null): VendorPayment
    {
        if ($inv->status === 'void') {
            throw new DomainException('This invoice was voided.');
        }
        if ($amountMinor <= 0 || $amountMinor > $inv->balanceMinor()) {
            throw new DomainException('The amount is between 0 and what is still owed ('.Money::format($inv->balanceMinor(), $inv->currency).').');
        }
        if (! isset(VendorPayment::METHODS[$method])) {
            throw new DomainException('Choose how it was paid.');
        }

        return DB::transaction(function () use ($actor, $inv, $amountMinor, $method, $reference, $receivedOn) {
            $p = VendorPayment::create(['invoice_id' => $inv->id, 'customer_id' => $inv->customer_id, 'received_on' => $receivedOn ?? now()->toDateString(),
                'amount_minor' => $amountMinor, 'method' => $method, 'reference' => $reference ? mb_substr(trim($reference), 0, 120) : null, 'recorded_by' => $actor->name]);
            if ($inv->balanceMinor() <= 0) {
                $inv->forceFill(['status' => 'paid'])->save();
            }
            $this->audit->record($actor, 'vendor.payment.received', $p, ['amount_minor' => [null, $amountMinor]], $reference);

            return $p;
        });
    }

    public function void(Actor $actor, VendorInvoice $inv, string $reason): VendorInvoice
    {
        if (trim($reason) === '' || $inv->payments()->exists()) {
            throw new DomainException(trim($reason) === '' ? 'Give the reason.' : 'An invoice with payments cannot be voided.');
        }

        return DB::transaction(function () use ($actor, $inv, $reason) {
            $inv->forceFill(['status' => 'void', 'void_reason' => mb_substr(trim($reason), 0, 255)])->save();
            $this->audit->record($actor, 'vendor.invoice.voided', $inv, ['status' => ['open', 'void']], $reason);

            return $inv;
        });
    }

    /** @return array{arr: int, outstanding: int, overdue: int, collected_month: int} in GHS minor units */
    public static function figures(): array
    {
        $active = VendorLicense::whereNull('revoked_at')->whereNull('superseded_by')->where('kind', 'paid')->where('expires_on', '>=', now()->toDateString())->get(['price_minor', 'starts_on', 'expires_on']);
        $arr = (int) $active->sum(fn ($l) => $l->starts_on && $l->expires_on ? (int) round($l->price_minor * 365 / max(1, $l->starts_on->diffInDays($l->expires_on) + 1)) : $l->price_minor);
        $open = VendorInvoice::where('status', 'open')->get();

        return ['arr' => $arr, 'outstanding' => (int) $open->sum(fn ($i) => $i->balanceMinor()),
            'overdue' => (int) $open->filter(fn ($i) => $i->due_on->isPast())->sum(fn ($i) => $i->balanceMinor()),
            'collected_month' => (int) VendorPayment::where('received_on', '>=', now()->startOfMonth()->toDateString())->sum('amount_minor')];
    }
}
