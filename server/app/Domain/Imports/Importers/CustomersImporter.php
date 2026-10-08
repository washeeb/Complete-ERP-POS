<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Customers\CustomerService;
use App\Domain\Imports\Importer;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Customers: matched by customer code, else by phone number; anything else is a new customer. Empty cells leave a detail
 * unchanged. Credit limits and payment terms need the credit permission and are set at head office.
 */
final class CustomersImporter extends Importer
{
    public function __construct(private readonly CustomerService $customers) {}

    public function key(): string
    {
        return 'customers';
    }

    public function label(): string
    {
        return 'Customers (create and update)';
    }

    public function description(): string
    {
        return 'One row per customer, matched by code, else by phone number; others are created (with the next CUS number if the code is empty). '
            .'Empty cells leave a detail unchanged. credit_limit (empty = no credit) and payment_terms_days need the credit permission and are set at head office. '
            .'Only import customers who agreed to be on your records.';
    }

    public function permission(): string
    {
        return 'customers.manage';
    }

    public function columns(): array
    {
        return [
            ['name' => 'code', 'required' => false, 'help' => 'Customer code (e.g. CUS-HQ-000012). Empty for a new customer = next number.', 'example' => ''],
            ['name' => 'name', 'required' => 'new', 'help' => 'Customer or business name.', 'example' => 'Kofi Provisions'],
            ['name' => 'phone', 'required' => false, 'help' => 'Phone number (0244… or +233…). Must be unique.', 'example' => '0244123456'],
            ['name' => 'email', 'required' => false, 'help' => 'E-mail address.', 'example' => ''],
            ['name' => 'contact_name', 'required' => false, 'help' => 'Contact person (businesses).', 'example' => 'Kofi Mensah'],
            ['name' => 'tax_no', 'required' => false, 'help' => 'TIN or Ghana Card number (for VAT invoices).', 'example' => ''],
            ['name' => 'address', 'required' => false, 'help' => 'Address.', 'example' => 'Kejetia, Kumasi'],
            ['name' => 'price_list', 'required' => false, 'help' => 'Price list code they buy on (e.g. WHOLESALE).', 'example' => 'WHOLESALE'],
            ['name' => 'credit_limit', 'required' => false, 'help' => 'Credit limit in your currency (0 = none allowed beyond what is paid).', 'example' => '5000'],
            ['name' => 'payment_terms_days', 'required' => false, 'help' => 'Days to pay an invoice.', 'example' => '30'],
        ];
    }

    public function reference(array $row): string
    {
        return (string) ($row['code'] ?? '') ?: (string) ($row['name'] ?? $row['phone'] ?? '');
    }

    public function apply(Actor $actor, array $row, array $options, ?Model $context): string
    {
        $customer = self::has($row, 'code') ? Customer::where('code', strtoupper($row['code']))->first() : null;
        if (! $customer && self::has($row, 'phone') && ($phone = CustomerService::normalisePhone($row['phone']))) {
            $customer = Customer::where('phone', $phone)->first();
        }
        $isNew = ! $customer;
        $data = [];
        if ($isNew) {
            $data['name'] = self::need($row, 'name', 'the name (new customer)');
            if (self::has($row, 'code')) {
                $data['code'] = $row['code'];
            }
        }
        foreach (['name', 'phone', 'email', 'contact_name', 'tax_no', 'address'] as $f) {
            if (self::has($row, $f)) {
                $data[$f] = $row[$f];
            }
        }
        if (self::has($row, 'price_list')) {
            $data['price_list_id'] = PriceList::where('code', strtoupper($row['price_list']))->value('id') ?? throw new DomainException("Unknown price list {$row['price_list']}.");
        }
        $currency = Tenant::find($actor->tenantId)->currency_code;
        $limit = self::has($row, 'credit_limit') ? Money::toMinor(self::number($row['credit_limit'], 'credit_limit'), $currency) : null;
        $terms = self::has($row, 'payment_terms_days') ? (int) self::number($row['payment_terms_days'], 'payment_terms_days') : null;
        if ($isNew && ($limit !== null || $terms !== null)) {
            $data['credit_limit_minor'] = $limit;
            $data['payment_terms_days'] = $terms ?? 0;
        }
        $saved = $this->customers->save($actor, $customer ?? new Customer, $data, 'CSV import');
        if (! $isNew && ($limit !== null || $terms !== null)
            && ($limit !== $saved->credit_limit_minor || ($terms !== null && $terms !== (int) $saved->payment_terms_days))) {
            $this->customers->setCredit($actor, $saved, $limit ?? $saved->credit_limit_minor, $terms ?? (int) $saved->payment_terms_days, (bool) $saved->credit_hold, 'CSV import');
        }

        return $isNew ? 'created' : 'updated';
    }

    public function exportRows(Actor $actor, array $options, ?Model $context): iterable
    {
        $currency = Tenant::find($actor->tenantId)->currency_code;
        foreach (Customer::with('priceList')->orderBy('code')->lazy(500) as $c) {
            yield [$c->code, $c->name, $c->phone, $c->email, $c->contact_name, $c->tax_no, $c->address, $c->priceList?->code,
                $c->credit_limit_minor === null ? '' : Money::format($c->credit_limit_minor, $currency), $c->payment_terms_days];
        }
    }
}
