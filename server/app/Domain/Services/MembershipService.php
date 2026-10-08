<?php

namespace App\Domain\Services;

use App\Domain\Modules\ModuleService;
use App\Domain\Pricing\TaxCalculator;
use App\Domain\Sales\InvoiceService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Tenant;
use App\Security\Authorizer;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Memberships and subscriptions (owner decision 2026-10-19: recurring invoiced memberships as well as prepaid packs).
 * A plan has a price per period (1, 3, 6 or 12 months) and, optionally, sessions included each period. A customer's
 * membership is invoiced on the first day of each period — the invoice goes on their account (Receivables) and is paid
 * like any other — and that period's sessions become a pass. Paused memberships are not billed; a cancelled one runs
 * to the end of the period already invoiced.
 *
 * Billing runs at head office only (erp:bill-memberships, daily), so a period is never invoiced twice by two servers.
 */
final class MembershipService
{
    public const INTERVALS = [1 => 'Monthly', 3 => 'Every 3 months', 6 => 'Every 6 months', 12 => 'Yearly'];

    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly ModuleService $modules,
        private readonly InvoiceService $invoices,
        private readonly PassService $passes,
    ) {}

    /** @param array{code?: string, name?: string, product_id?: string, price?: string, price_minor?: int, interval_months?: int, items?: array, description?: ?string, is_active?: bool} $data */
    public function savePlan(Actor $actor, MembershipPlan $plan, array $data): MembershipPlan
    {
        $this->can($actor, 'services.memberships');
        $plan->fill(array_intersect_key($data, array_flip(['code', 'name', 'product_id', 'interval_months', 'description', 'is_active'])));
        if (array_key_exists('price', $data)) {
            $plan->price_minor = Money::toMinor((string) $data['price'], Tenant::find($actor->tenantId)->currency_code);
        } elseif (array_key_exists('price_minor', $data)) {
            $plan->price_minor = (int) $data['price_minor'];
        }
        $plan->code = strtoupper(trim((string) $plan->code));
        $plan->name = trim((string) $plan->name);
        $plan->interval_months = (int) ($plan->interval_months ?: 1);
        if (! preg_match('/^[A-Z0-9][A-Z0-9_-]{0,29}$/', $plan->code) || $plan->name === '') {
            throw new DomainException('Enter a code (letters, digits, dash) and a name.');
        }
        if (! array_key_exists($plan->interval_months, self::INTERVALS) || (int) $plan->price_minor <= 0) {
            throw new DomainException('Choose how often it is billed and enter the price per period.');
        }
        if (! Product::where('track_stock', false)->find($plan->product_id)) {
            throw new DomainException('Choose the product it is invoiced as (one without stock; its tax group taxes the invoice).');
        }
        if (array_key_exists('items', $data)) {
            $plan->items = PassService::normaliseItems((array) $data['items'], true);
        }
        $plan->items ??= [];
        $plan->is_active ??= true;
        $plan->tenant_id ??= $actor->tenantId;
        $isNew = ! $plan->exists;

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $plan, $isNew ? 'membership_plan.created' : 'membership_plan.updated'));
    }

    /** Sign a customer up. The first period is invoiced straight away when it starts today or earlier. */
    public function enrol(Actor $actor, Customer $customer, MembershipPlan $plan, Branch $branch, ?string $startsOn = null, ?int $priceMinor = null): Membership
    {
        $this->require($actor, $branch);
        if (! $plan->is_active) {
            throw new DomainException('That plan is no longer offered.');
        }
        $starts = Carbon::parse($startsOn ?? now()->toDateString())->startOfDay();
        if ($starts->lt(now()->startOfDay()->subDays(31))) {
            throw new DomainException('A membership can start at most a month back.');
        }
        if (Membership::where('customer_id', $customer->id)->where('membership_plan_id', $plan->id)->whereIn('status', ['active', 'paused'])->exists()) {
            throw new DomainException("{$customer->name} is already a member of {$plan->name}.");
        }
        $price = $priceMinor ?? $plan->price_minor;
        if ($price <= 0) {
            throw new DomainException('Enter the price per period.');
        }

        $m = $this->recorder->transaction(function () use ($actor, $customer, $plan, $branch, $starts, $price) {
            $m = (new Membership)->forceFill(['tenant_id' => $customer->tenant_id, 'number' => $this->numbers->next($customer->tenant_id, 'membership'),
                'customer_id' => $customer->id, 'membership_plan_id' => $plan->id, 'branch_id' => $branch->id, 'status' => 'active', 'price_minor' => $price,
                'starts_on' => $starts->toDateString(), 'next_bill_on' => $starts->toDateString()]);

            return $this->recorder->save($actor, $m, 'membership.started', branchId: $branch->id);
        });
        if (config('erp.node.role') !== 'branch') {
            $this->billDue($actor, null, $m); // the first period now; branch servers leave it to head office
        }

        return $m->fresh();
    }

    public function pause(Actor $actor, Membership $m, string $reason): Membership
    {
        $this->require($actor, Branch::findOrFail($m->branch_id));
        if ($m->status !== 'active') {
            throw new DomainException('Only an active membership can be paused.');
        }
        $m->status = 'paused';

        return $this->recorder->save($actor, $m, 'membership.paused', $reason, $m->branch_id);
    }

    /** Billing restarts from today (or the period already paid for, if later). */
    public function resume(Actor $actor, Membership $m): Membership
    {
        $this->require($actor, Branch::findOrFail($m->branch_id));
        if ($m->status !== 'paused') {
            throw new DomainException('Only a paused membership can be resumed.');
        }
        $m->forceFill(['status' => 'active', 'next_bill_on' => max($m->next_bill_on->toDateString(), now()->toDateString())]);

        return $this->recorder->save($actor, $m, 'membership.resumed', branchId: $m->branch_id);
    }

    /** No more invoices; the membership is covered to the end of the period already invoiced. */
    public function cancel(Actor $actor, Membership $m, string $reason): Membership
    {
        $this->require($actor, Branch::findOrFail($m->branch_id));
        if ($m->status === 'cancelled' || trim($reason) === '') {
            throw new DomainException($m->status === 'cancelled' ? 'That membership is already cancelled.' : 'Give the reason.');
        }
        $m->forceFill(['status' => 'cancelled', 'cancel_reason' => mb_substr(trim($reason), 0, 255),
            'ends_on' => max($m->starts_on->toDateString(), $m->next_bill_on->copy()->subDay()->toDateString())]);

        return $this->recorder->save($actor, $m, 'membership.cancelled', $reason, $m->branch_id);
    }

    /**
     * Invoice every period that has started (up to $asOf): one invoice and, when the plan includes sessions, one pass per
     * period. Catches up periods missed while the server was off. Returns the number of invoices raised.
     */
    public function billDue(Actor $actor, ?string $asOf = null, ?Membership $only = null): int
    {
        if (config('erp.node.role') === 'branch') {
            return 0; // head office bills; the invoices reach branches by sync
        }
        $asOf = Carbon::parse($asOf ?? now()->toDateString())->toDateString();
        $n = 0;
        $due = Membership::where('status', 'active')->where('next_bill_on', '<=', $asOf)->when($only, fn ($q) => $q->whereKey($only->id))->orderBy('next_bill_on')->get();
        foreach ($due as $m) {
            $n += $this->recorder->transaction(function () use ($actor, $m, $asOf) {
                $m = Membership::lockForUpdate()->findOrFail($m->id);
                $raised = 0;
                while ($m->status === 'active' && $m->next_bill_on->toDateString() <= $asOf && $raised < 24) {
                    $this->billPeriod($actor, $m);
                    $raised++;
                }

                return $raised;
            });
        }

        return $n;
    }

    /** The invoices of a membership, newest first. */
    public static function invoices(Membership $m)
    {
        return Invoice::where('membership_id', $m->id)->orderByDesc('issued_on')->get();
    }

    private function billPeriod(Actor $actor, Membership $m): void
    {
        $plan = MembershipPlan::findOrFail($m->membership_plan_id);
        $customer = Customer::findOrFail($m->customer_id);
        $product = Product::with('taxGroup.rates', 'units.uom')->findOrFail($plan->product_id);
        $unit = $product->units->firstWhere('is_base', true) ?? $product->units->first();
        $list = PriceList::where('is_default', true)->firstOrFail();
        $from = $m->next_bill_on->copy();
        $to = $from->copy()->addMonthsNoOverflow($plan->interval_months);
        $rates = $product->taxGroup->rates->where('is_active', true)->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all();
        $t = TaxCalculator::line($m->price_minor, $rates, $list->prices_include_tax);
        $period = $from->format('j M Y').' – '.$to->copy()->subDay()->format('j M Y');
        $invoice = $this->invoices->raise($actor, $customer, [[
            'product_id' => $product->id, 'product_uom_id' => $unit->id, 'sku' => $product->sku, 'name' => mb_substr("{$plan->name} ({$period})", 0, 160),
            'uom_code' => $unit->uom->code, 'factor' => $unit->factor, 'qty' => '1.000', 'unit_price_minor' => $m->price_minor,
            'net_minor' => $t['net'], 'tax_minor' => $t['tax'], 'total_minor' => $t['gross'], 'tax_breakdown' => $t['breakdown'],
        ]], ['branch_id' => $m->branch_id, 'currency_code' => $list->currency_code, 'prices_include_tax' => $list->prices_include_tax,
            'membership_id' => $m->id, 'customer_ref' => $m->number]);

        $limited = array_filter($plan->items, fn ($it) => $it['sessions'] !== null);
        if ($plan->items !== []) {
            $sessions = array_sum(array_column($limited, 'sessions'));
            $value = count($limited) === count($plan->items) && $sessions > 0 ? intdiv($t['net'], $sessions) : 0;
            $this->passes->issue($actor, $customer, $m->branch_id, 'membership', "{$plan->name} ({$period})", $plan->items, $from, $to->copy()->subDay(), $value,
                ['membership_id' => $m->id]);
        }
        $m->forceFill(['next_bill_on' => $to->toDateString(), 'last_invoice_id' => $invoice->id]);
        $this->recorder->save($actor, $m, 'membership.billed', branchId: $m->branch_id, payload: ['invoice' => $invoice->number]);
    }

    private function require(Actor $actor, Branch $branch): void
    {
        if (! $this->modules->isEnabled($branch, 'services')) {
            throw new DomainException('The Services module is not on at this branch.', 'module_disabled');
        }
        if (! $actor->user || ! $this->authz->allows($actor->user, 'services.memberships', $branch)) {
            throw new AccessDenied;
        }
    }

    private function can(Actor $actor, string $permission): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, $permission)) {
            throw new AccessDenied;
        }
    }
}
