<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Customers\StoredValues;
use App\Domain\Imports\Importer;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\StoredValue;
use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Gift cards and store credit still unspent in the old system (data migration). Each keeps its card code, so a holder
 * can use the card they already have; the debt to holders is balanced against "Opening balances (to clear)".
 */
final class GiftCardsImporter extends Importer
{
    public function __construct(private readonly StoredValues $storedValues) {}

    public function key(): string
    {
        return 'gift_cards';
    }

    public function label(): string
    {
        return 'Gift cards and store credit (migration)';
    }

    public function description(): string
    {
        return 'One row per unspent gift card or store credit. code is the number printed on the card (at least 6 letters or digits); '
            .'balance is what is left on it. kind is gift_card (default) or store_credit; store credit needs the customer (code, phone or exact name). A code already loaded is skipped.';
    }

    public function permission(): string
    {
        return 'giftcards.manage';
    }

    public function columns(): array
    {
        return [
            ['name' => 'code', 'required' => true, 'help' => 'Card number (letters and digits).', 'example' => 'GC00012345'],
            ['name' => 'balance', 'required' => true, 'help' => 'Unspent amount.', 'example' => '200.00'],
            ['name' => 'kind', 'required' => false, 'help' => 'gift_card or store_credit.', 'example' => 'gift_card'],
            ['name' => 'customer', 'required' => false, 'help' => 'Customer code, phone or exact name (needed for store credit).', 'example' => ''],
        ];
    }

    public function apply(Actor $actor, array $row, array $options, ?Model $context): string
    {
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', self::need($row, 'code', 'the card number')));
        $kind = self::has($row, 'kind') ? str_replace([' ', '-'], '_', strtolower(trim($row['kind']))) : 'gift_card';
        if (! array_key_exists($kind, StoredValue::KINDS)) {
            throw new DomainException('kind: write gift_card or store_credit.');
        }
        $customer = self::has($row, 'customer') ? Lookup::customer($row['customer']) : null;
        if ($kind === 'store_credit' && ! $customer) {
            throw new DomainException('Store credit belongs to a customer: fill in the customer column.');
        }
        $amount = Money::toMinor(self::number(self::need($row, 'balance', 'the balance'), 'balance'), Tenant::find($actor->tenantId)->currency_code);
        if ($amount <= 0 || StoredValue::where('code', $code)->exists()) {
            return 'unchanged';
        }
        $this->storedValues->issue($actor, $kind, $amount, "{$kind}_opening", (string) Str::uuid7(), 'Opening balance', $customer, $code);

        return 'added';
    }
}
