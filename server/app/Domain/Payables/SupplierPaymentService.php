<?php

namespace App\Domain\Payables;

use App\Domain\Purchasing\SupplierOnboarding;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Tenant;
use App\Security\Authorizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Money paid to suppliers (needs payables.pay — kept apart from entering bills), and supplier balances brought
 * forward at go-live (finance.journal). A payment settles the oldest open bills first, or the ones chosen.
 * With withholding tax on, the amount settles the bills in full and the cash paid out is that less the tax kept back
 * (owed to GRA, with a certificate for the supplier).
 */
final class SupplierPaymentService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly SupplierLedger $ledger,
    ) {}

    /** @param list<string>|null $billIds */
    /**
     * @param  int  $amountMinor  what the payment settles on the supplier's account (before withholding tax)
     * @param  bool  $withhold  false: no withholding tax on this payment (e.g. the supplier showed a new exemption)
     * @param  ?int  $whtMinor  the tax to keep back when it differs from the computed one (the accountant's figure)
     */
    public function pay(Actor $actor, Supplier $supplier, Branch $branch, Account $from, string $method, int $amountMinor, ?string $reference = null,
        ?string $paidOn = null, ?array $billIds = null, ?string $note = null, bool $withhold = true, ?int $whtMinor = null): SupplierPayment
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'payables.pay', $branch)) {
            throw new AccessDenied;
        }
        if (! array_key_exists($method, SupplierPayment::METHODS)) {
            throw new DomainException('Choose how the supplier was paid.');
        }
        if ($amountMinor <= 0) {
            throw new DomainException('Enter an amount above zero.');
        }
        if ($from->type !== 'asset' || $from->is_header || $from->control || ! $from->is_active) {
            throw new DomainException("Pay from a cash, bank or mobile money account, not {$from->label()}.");
        }
        app(SupplierOnboarding::class)->assertBankVerified($supplier, $method);
        if (in_array($method, ['bank', 'momo', 'cheque'], true) && trim((string) $reference) === '') {
            throw new DomainException('Enter the transfer, MoMo or cheque reference.');
        }
        $day = Carbon::parse($paidOn ?? now()->toDateString());
        if ($day->isAfter(now()->endOfDay())) {
            throw new DomainException('A payment cannot be dated in the future.');
        }

        return $this->recorder->transaction(function () use ($actor, $supplier, $branch, $from, $method, $amountMinor, $reference, $day, $billIds, $note, $withhold, $whtMinor) {
            DB::table('supplier_balances')->where('supplier_id', $supplier->id)->lockForUpdate()->first();
            $plan = $this->ledger->plan($supplier->id, $amountMinor, $billIds);
            $wht = $withhold ? app(WithholdingTax::class)->compute($supplier, $amountMinor, $plan) : ['wht' => 0, 'base' => 0, 'rate_bp' => 0];
            if ($withhold && $whtMinor !== null) {
                if ($whtMinor < 0 || $whtMinor >= $amountMinor) {
                    throw new DomainException('The withholding tax must be at least 0 and less than the amount.');
                }
                $wht['wht'] = $whtMinor;
            }
            $p = new SupplierPayment;
            $p->forceFill(['tenant_id' => $supplier->tenant_id, 'number' => $this->numbers->next($supplier->tenant_id, 'supplier_payment'), 'supplier_id' => $supplier->id,
                'branch_id' => $branch->id, 'account_id' => $from->id, 'method' => $method, 'reference' => $reference ? mb_substr(trim($reference), 0, 80) : null,
                'currency_code' => Tenant::find($supplier->tenant_id)->currency_code, 'amount_minor' => $amountMinor, 'paid_on' => $day->toDateString(),
                'paid_by_name' => $actor->name, 'note' => $note, 'wht_minor' => $wht['wht'],
                'wht_rate_bp' => $wht['wht'] > 0 ? $wht['rate_bp'] : null, 'wht_base_minor' => $wht['wht'] > 0 ? $wht['base'] : null,
                'wht_certificate_no' => $wht['wht'] > 0 ? $this->numbers->next($supplier->tenant_id, 'wht_certificate') : null]);
            $this->recorder->save($actor, $p, 'supplier_payment.made', $wht['wht'] > 0 ? "Withholding tax {$p->wht_certificate_no} kept back" : null, $branch->id);
            $this->ledger->post($actor, $supplier, 'payment', -$amountMinor, $p->currency_code, 'supplier_payment', $p->id, $p->number, null, $branch->id,
                $day->copy()->setTimeFrom(now()), $plan);

            return $p;
        });
    }

    /** What the company owed a supplier on the go-live date (one open item; the automatic posting balances it against opening balances). */
    public function opening(Actor $actor, Supplier $supplier, int $amountMinor, string $asOf, ?string $dueOn = null, ?string $reference = null): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'finance.journal')) {
            throw new AccessDenied;
        }
        if ($amountMinor <= 0) {
            throw new DomainException('Enter what is owed to the supplier.');
        }
        if (DB::table('supplier_ledger')->where('supplier_id', $supplier->id)->exists()) {
            throw new DomainException("{$supplier->name} already has bills or payments; enter what is owed as a bill instead.");
        }
        $day = Carbon::parse($asOf);
        $this->recorder->transaction(function () use ($actor, $supplier, $amountMinor, $day, $dueOn, $reference) {
            $id = (string) Str::uuid7();
            $this->ledger->post($actor, $supplier, 'opening', $amountMinor, Tenant::find($supplier->tenant_id)->currency_code, 'supplier_opening', $id,
                mb_substr($reference ?: 'Opening balance', 0, 60), $dueOn ? Carbon::parse($dueOn) : $day, null, $day->copy()->setTime(12, 0));
            $this->recorder->audit->record($actor, 'supplier.opening_balance', $supplier, ['opening_minor' => [null, $amountMinor]], $reference);
        });
    }
}
