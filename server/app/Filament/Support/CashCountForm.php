<?php

namespace App\Filament\Support;

use App\Domain\Pos\CashControl;
use App\Support\Money;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\FontWeight;

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

        // Notes and coins in their own groups, and the total as it is typed (the expected figure is never shown).
        $field = fn ($d, $key) => TextInput::make("denoms.{$key}")->label(preg_replace('/ (note|coin)$/', '', $d[0]))
            ->numeric()->integer()->minValue(0)->placeholder('0')->live(onBlur: true);
        $group = fn (string $title, bool $notes) => Fieldset::make($title)->columns(['default' => 2, 'md' => 4])->schema(collect($list)
            ->filter(fn ($d, $key) => str_starts_with($key, 'n') === $notes)->map($field)->values()->all());

        return [
            $group('Notes', true),
            $group('Coins', false),
            Text::make(fn (Get $get) => 'Total counted: '.Money::format(CashControl::total($currency, collect($get('denoms') ?? [])
                ->map(fn ($v) => (int) ($v ?: 0))->all(), null)[0], $currency))->weight(FontWeight::Bold),
        ];
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
