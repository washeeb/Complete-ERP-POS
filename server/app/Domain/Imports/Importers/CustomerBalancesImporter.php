<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Customers\CustomerService;
use App\Domain\Customers\StoredValues;
use App\Domain\Imports\Importer;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Models\Tenant;
use App\Security\Authorizer;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * What customers owed on the go-live date (data migration). A positive balance becomes the customer's opening balance
 * (an open item they pay off like an invoice); a negative one — the shop owes the customer — becomes store credit they
 * can spend at the till. Both are balanced against "Opening balances (to clear)" by the automatic posting.
 */
final class CustomerBalancesImporter extends Importer
{
    public function __construct(private readonly CustomerService $customers, private readonly StoredValues $storedValues, private readonly Authorizer $authz) {}

    public function key(): string
    {
        return 'customer_balances';
    }

    public function label(): string
    {
        return 'Customer balances owed (migration)';
    }

    public function description(): string
    {
        return 'One row per customer who owed money (or was owed) on the go-live date. customer is the customer code, phone number or exact name. '
            .'A positive balance is what they owe you; a negative balance (you owe them) becomes store credit. A customer can only get an opening balance before their first sale on account.';
    }

    public function permission(): string
    {
        return 'customers.credit.manage';
    }

    public function optionDefaults(): array
    {
        return ['as_of' => now()->toDateString()];
    }

    public function columns(): array
    {
        return [
            ['name' => 'customer', 'required' => true, 'help' => 'Customer code, phone number or exact name.', 'example' => '0244123456'],
            ['name' => 'balance', 'required' => true, 'help' => 'What they owe you (negative = you owe them).', 'example' => '1250.00'],
        ];
    }

    public function reference(array $row): string
    {
        return (string) ($row['customer'] ?? '');
    }

    public function apply(Actor $actor, array $row, array $options, ?Model $context): string
    {
        $customer = Lookup::customer(self::need($row, 'customer', 'the customer'));
        $amount = Money::toMinor(self::number(self::need($row, 'balance', 'the balance'), 'balance'), Tenant::find($actor->tenantId)->currency_code);
        if ($amount === 0) {
            return 'unchanged';
        }
        $asOf = (string) ($options['as_of'] ?? now()->toDateString());
        if ($amount > 0) {
            $this->customers->openingBalance($actor, $customer, $amount, $asOf);
        } else {
            if (! $actor->user || ! $this->authz->allowsAnywhere($actor->user, 'giftcards.manage')) {
                throw new AccessDenied;
            }
            $this->storedValues->issue($actor, 'store_credit', -$amount, 'store_credit_opening', (string) Str::uuid7(), 'Opening balance', $customer);
        }

        return 'added';
    }
}
