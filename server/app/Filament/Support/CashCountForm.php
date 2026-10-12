<?php

namespace App\Filament\Support;

use App\Domain\Pos\CashControl;
use App\Support\Money;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;

/**
 * A blind count, note by note and coin by coin (or one amount when the currency has no list). It never shows the
 * figure being checked against: the person counts first, the system compares afterwards.
 */
final class CashCountForm
{
    /** @return array<int, mixed> */
    public static function schema(string $currency): array
    {
        $list = CashControl::denominations($currency);
        if (! $list) {
            return [TextInput::make('amount')->label('Amount counted')->numeric()->required()];
        }

        return [Grid::make(3)->schema(collect($list)->map(fn ($d, $key) => TextInput::make("denoms.{$key}")->label($d[0])
            ->numeric()->integer()->minValue(0)->placeholder('0'))->values()->all())];
    }

    /** @return array{0: ?array<string, int>, 1: ?int} [denoms, amount] */
    public static function read(array $data, string $currency): array
    {
        if (CashControl::denominations($currency)) {
            return [collect($data['denoms'] ?? [])->map(fn ($v) => (int) ($v ?: 0))->all(), null];
        }

        return [null, Money::toMinor((string) ($data['amount'] ?? '0'), $currency)];
    }
}
