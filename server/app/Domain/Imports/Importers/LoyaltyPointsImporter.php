<?php

namespace App\Domain\Imports\Importers;

use App\Domain\Customers\Loyalty;
use App\Domain\Imports\Importer;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Loyalty points customers had in the old system (data migration). They are added once per customer as an "opening"
 * entry that does not expire; the debt to customers is balanced against "Opening balances (to clear)".
 */
final class LoyaltyPointsImporter extends Importer
{
    public function __construct(private readonly Loyalty $loyalty) {}

    public function key(): string
    {
        return 'loyalty_points';
    }

    public function label(): string
    {
        return 'Loyalty points balances (migration)';
    }

    public function description(): string
    {
        return 'One row per customer with the points they had in the old system. customer is the customer code, phone number or exact name. '
            .'Points are added once per customer (a second file skips customers who already have opening points) and do not expire. 1 point = 1 pesewa, as at the till.';
    }

    public function permission(): string
    {
        return 'customers.manage';
    }

    public function columns(): array
    {
        return [
            ['name' => 'customer', 'required' => true, 'help' => 'Customer code, phone number or exact name.', 'example' => '0244123456'],
            ['name' => 'points', 'required' => true, 'help' => 'Points balance (whole number).', 'example' => '350'],
        ];
    }

    public function reference(array $row): string
    {
        return (string) ($row['customer'] ?? '');
    }

    public function apply(Actor $actor, array $row, array $options, ?Model $context): string
    {
        $customer = Lookup::customer(self::need($row, 'customer', 'the customer'));
        $points = self::number(self::need($row, 'points', 'the points'), 'points');
        if (! preg_match('/^\+?\d+(\.0+)?$/', $points)) {
            throw new DomainException('points: write a whole number, zero or more.');
        }
        $points = (int) $points;
        if ($points === 0 || DB::table('loyalty_ledger')->where('customer_id', $customer->id)->where('type', 'opening')->exists()) {
            return 'unchanged';
        }
        $this->loyalty->post($actor, $customer, 'opening', $points, 'loyalty_opening', (string) Str::uuid7(), 'Opening balance');

        return 'added';
    }
}
