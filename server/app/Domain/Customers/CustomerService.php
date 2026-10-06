<?php

namespace App\Domain\Customers;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Tenant;
use App\Security\Authorizer;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Customers: profile, marketing consent (who agreed, when, how — never assumed), credit (head office only)
 * and the credit check used by orders and till credit sales (owner decision: over the limit → hold for approval).
 */
final class CustomerService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly CustomerLedger $ledger,
    ) {}

    /** @param array<string, mixed> $data profile fields; 'consents' => [channel => bool] records a change of consent */
    public function save(Actor $actor, Customer $customer, array $data, string $consentSource = 'in person'): Customer
    {
        $this->require($actor, 'customers.manage');
        if (trim((string) ($data['name'] ?? $customer->name)) === '') {
            throw new DomainException('Enter the customer’s name.');
        }
        $phone = isset($data['phone']) ? self::normalisePhone((string) $data['phone']) : $customer->phone;
        if ($phone && Customer::where('phone', $phone)->when($customer->exists, fn ($q) => $q->whereKeyNot($customer->id))->exists()) {
            throw new DomainException("Another customer already has the phone number {$phone}.", 'duplicate_phone');
        }
        if (isset($data['email']) && $data['email'] !== '' && $data['email'] !== null && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('That email address is not valid.');
        }
        if (! empty($data['price_list_id']) && ! PriceList::whereKey($data['price_list_id'])->exists()) {
            throw new DomainException('Choose a valid price list.');
        }
        if (array_intersect_key($data, array_flip(Customer::CREDIT_FIELDS)) !== [] && $customer->exists) {
            throw new DomainException('Credit limits and terms are changed with “Credit”, at head office.');
        }

        return $this->recorder->transaction(function () use ($actor, $customer, $data, $phone, $consentSource) {
            $isNew = ! $customer->exists;
            $customer->fill(array_intersect_key($data, array_flip(['type', 'name', 'contact_name', 'email', 'tax_no', 'address', 'price_list_id', 'home_branch_id', 'tags', 'notes', 'is_active'])));
            $customer->phone = $phone;
            if (array_key_exists('consents', $data)) {
                $customer->consents = $this->consents($actor, $customer->consents ?? [], (array) $data['consents'], $consentSource);
            }
            if ($isNew) {
                $customer->tenant_id = $actor->tenantId;
                $customer->code = strtoupper(trim((string) ($data['code'] ?? ''))) ?: $this->numbers->next($actor->tenantId, 'customer');
                if (Customer::where('code', $customer->code)->exists()) {
                    throw new DomainException("Customer code {$customer->code} is already used.");
                }
                $customer->consents ??= [];
                $customer->tags ??= [];
                if (isset($data['credit_limit_minor']) || isset($data['payment_terms_days'])) { // first set-up at head office
                    $this->assertCreditHere($actor);
                    $customer->credit_limit_minor = $data['credit_limit_minor'] ?? null;
                    $customer->payment_terms_days = (int) ($data['payment_terms_days'] ?? 0);
                }
            }
            $this->stamp($customer);

            return $this->recorder->save($actor, $customer, $isNew ? 'customer.created' : 'customer.updated', branchId: $customer->home_branch_id);
        });
    }

    /** Credit limit (null = no credit), payment terms and the manual credit stop. Head office only (spec §4.2). */
    public function setCredit(Actor $actor, Customer $customer, ?int $limitMinor, int $termsDays, bool $hold, ?string $reason = null): Customer
    {
        $this->assertCreditHere($actor);
        if ($limitMinor !== null && $limitMinor < 0) {
            throw new DomainException('A credit limit cannot be negative.');
        }
        if ($termsDays < 0 || $termsDays > 365) {
            throw new DomainException('Payment terms are 0 to 365 days.');
        }
        $customer->credit_limit_minor = $limitMinor;
        $customer->payment_terms_days = $termsDays;
        $customer->credit_hold = $hold;
        $this->stamp($customer);

        return $this->recorder->save($actor, $customer, 'customer.credit_changed', $reason, $customer->home_branch_id);
    }

    /**
     * Why extra credit of $amountMinor would not be automatic, or [] when it fits.
     *
     * @return list<string>
     */
    public function creditProblems(Customer $customer, int $amountMinor, ?string $exceptOrderId = null): array
    {
        $currency = Tenant::find($customer->tenant_id)->currency_code;
        $problems = [];
        if ($customer->credit_hold) {
            $problems[] = 'the account is on credit hold';
        }
        if ($customer->credit_limit_minor === null) {
            $problems[] = 'the customer has no credit account';

            return $problems;
        }
        if (($overdue = $this->ledger->overdueMinor($customer->id)) > 0) {
            $problems[] = Money::display($overdue, $currency).' is overdue';
        }
        $exposure = $this->exposure($customer, $exceptOrderId) + $amountMinor;
        if ($exposure > $customer->credit_limit_minor) {
            $problems[] = 'this would bring the account to '.Money::display($exposure, $currency).', over the limit of '.Money::display($customer->credit_limit_minor, $currency);
        }

        return $problems;
    }

    /**
     * How much an override must cover: the part over the limit — or everything when the customer has no credit account,
     * is on credit hold or has overdue debt. An override limit is measured against this.
     */
    public function overrideAmount(Customer $customer, int $amountMinor, ?string $exceptOrderId = null): int
    {
        if ($customer->credit_limit_minor === null || $customer->credit_hold || $this->ledger->overdueMinor($customer->id) > 0) {
            return $amountMinor;
        }

        return max(0, min($amountMinor, $this->exposure($customer, $exceptOrderId) + $amountMinor - $customer->credit_limit_minor));
    }

    /** What the customer owes plus what is ordered and not yet delivered (it will be invoiced). */
    public function exposure(Customer $customer, ?string $exceptOrderId = null): int
    {
        $undelivered = (int) DB::table('customer_order_lines')->join('customer_orders', 'customer_orders.id', '=', 'customer_order_lines.customer_order_id')
            ->where('customer_orders.customer_id', $customer->id)->whereIn('customer_orders.status', ['on_hold', 'confirmed', 'partially_delivered'])
            ->when($exceptOrderId, fn ($q) => $q->where('customer_orders.id', '<>', $exceptOrderId))
            ->sum(DB::raw('ROUND(customer_order_lines.total_minor * GREATEST(customer_order_lines.qty - customer_order_lines.delivered_qty, 0) / NULLIF(customer_order_lines.qty, 0))'));

        return $customer->balanceMinor() + $undelivered;
    }

    public function availableCredit(Customer $customer): ?int
    {
        return $customer->credit_limit_minor === null ? null : max(0, $customer->credit_limit_minor - $this->exposure($customer));
    }

    /** Opening balance when moving an existing account into the system (once per customer). */
    public function openingBalance(Actor $actor, Customer $customer, int $amountMinor, string $asOfDate): void
    {
        $this->assertCreditHere($actor);
        if (DB::table('customer_ledger')->where('customer_id', $customer->id)->exists()) {
            throw new DomainException('This customer already has transactions; record a payment or credit note instead.');
        }
        $currency = Tenant::find($customer->tenant_id)->currency_code;
        DB::transaction(function () use ($actor, $customer, $amountMinor, $asOfDate, $currency) {
            $this->ledger->post($actor, $customer, 'opening', $amountMinor, $currency, 'customer', $customer->id, 'Opening balance',
                Carbon::parse($asOfDate), $customer->home_branch_id, Carbon::parse($asOfDate));
            $this->recorder->audit->record($actor, 'customer.opening_balance', $customer, ['balance_minor' => [null, $amountMinor]], null, $customer->home_branch_id);
        });
    }

    public static function normalisePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 10) { // Ghana local 0XX XXX XXXX → +233
            $digits = '233'.substr($digits, 1);
        }
        if (strlen($digits) < 9 || strlen($digits) > 15) {
            throw new DomainException('That phone number is not valid.');
        }

        return '+'.$digits;
    }

    /** Field stamps for field-level merging between nodes: time|node of the change, and the stamp the editor saw. */
    private function stamp(Customer $customer): void
    {
        $stamps = $customer->field_stamps ?? [];
        $now = now()->utc()->format('Y-m-d\TH:i:s.u\Z').'|'.config('erp.node.id');
        foreach (Customer::MERGED_FIELDS as $f) {
            if (! $customer->exists || $customer->isDirty($f)) {
                $stamps[$f] = ['at' => $now, 'base' => $stamps[$f]['at'] ?? ''];
            }
        }
        $customer->field_stamps = $stamps;
    }

    private function consents(Actor $actor, array $current, array $wanted, string $source): array
    {
        foreach (Customer::CONSENT_CHANNELS as $channel => $label) {
            if (! array_key_exists($channel, $wanted)) {
                continue;
            }
            $granted = (bool) $wanted[$channel];
            if (($current[$channel]['granted'] ?? false) !== $granted) {
                $current[$channel] = ['granted' => $granted, 'at' => now()->toIso8601String(), 'source' => mb_substr($source, 0, 60), 'by' => $actor->name];
            }
        }

        return $current;
    }

    private function assertCreditHere(Actor $actor): void
    {
        $this->require($actor, 'customers.credit.manage');
        if (config('erp.node.role') === 'branch') {
            throw new DomainException('Credit limits and terms are set at head office; the change reaches this branch at the next sync.', 'hq_only');
        }
    }

    /** Customers are company-wide records: anyone holding the permission at some branch may maintain them. */
    private function require(Actor $actor, string $permission): void
    {
        if (! $actor->user || ! $this->authz->allowsAnywhere($actor->user, $permission)) {
            throw new AccessDenied;
        }
    }
}
