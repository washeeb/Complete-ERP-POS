<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Customers\CustomerService;
use App\Domain\Support\DomainException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Supplier;

/**
 * Finding the record a migration row is about, the way an old system names it: a customer by code, phone or exact
 * name; a supplier by code or exact name; an account by code, by "1010 Cash at bank", or by name.
 */
final class Lookup
{
    public static function customer(string $value): Customer
    {
        $v = trim($value);
        $found = Customer::where('code', strtoupper($v))->first();
        if (! $found && ($phone = CustomerService::normalisePhone($v)) && strlen(preg_replace('/\D/', '', $v)) >= 9) {
            $found = Customer::where('phone', $phone)->first();
        }
        if (! $found) {
            $byName = Customer::whereRaw('lower(name) = ?', [mb_strtolower($v)])->limit(2)->get();
            if ($byName->count() > 1) {
                throw new DomainException("More than one customer is called “{$v}”: use the customer code or phone number.");
            }
            $found = $byName->first();
        }

        return $found ?? throw new DomainException("No customer “{$v}”. Import the customers first.");
    }

    public static function supplier(string $value): Supplier
    {
        $v = trim($value);
        $found = Supplier::where('code', strtoupper($v))->first() ?? Supplier::whereRaw('lower(name) = ?', [mb_strtolower($v)])->first();

        return $found ?? throw new DomainException("No supplier “{$v}”. Import the suppliers first.");
    }

    public static function account(string $value): Account
    {
        $v = trim($value);
        $found = Account::where('code', $v)->first();
        if (! $found && preg_match('/^([0-9][0-9A-Za-z.\-]*)\s+[-–·:]?\s*(.+)$/u', $v, $m)) { // "1010 Cash at bank", "1010 - Cash"
            $found = Account::where('code', $m[1])->first() ?? Account::whereRaw('lower(name) = ?', [mb_strtolower(trim($m[2]))])->first();
        }
        if (! $found && str_contains($v, ':')) { // QuickBooks sub-accounts "Bank:Cash at bank"
            $found = Account::whereRaw('lower(name) = ?', [mb_strtolower(trim((string) strrchr($v, ':'), ': '))])->first();
        }
        $found ??= Account::whereRaw('lower(name) = ?', [mb_strtolower($v)])->first();

        return $found ?? throw new DomainException("No account “{$v}” in the chart of accounts. Import or add it first.");
    }
}
