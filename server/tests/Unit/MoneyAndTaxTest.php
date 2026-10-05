<?php

use App\Domain\Pricing\TaxCalculator;
use App\Domain\Support\DomainException;
use App\Support\Money;
use App\Support\Qty;

$gh = [['code' => 'VAT', 'name' => 'VAT', 'rate_bp' => 1500], ['code' => 'NHIL', 'name' => 'NHIL', 'rate_bp' => 250], ['code' => 'GETFUND', 'name' => 'GETFund', 'rate_bp' => 250]];

it('parses quantities to exact thousandths', function () {
    expect(Qty::toMilli('1'))->toBe(1000)
        ->and(Qty::toMilli('1.5'))->toBe(1500)
        ->and(Qty::toMilli('0.125'))->toBe(125)
        ->and(Qty::toMilli('-2.25'))->toBe(-2250)
        ->and(Qty::format(1500))->toBe('1.500')
        ->and(Qty::format(-125))->toBe('-0.125');
});

it('rejects quantities with more than three decimals', function () {
    Qty::toMilli('1.2345');
})->throws(DomainException::class);

it('converts units to base exactly', function () {
    expect(Qty::toBaseMilli(2000, Qty::factorMicro('24.000000')))->toBe(48000)   // 2 cartons of 24
        ->and(Qty::toBaseMilli(1500, Qty::factorMicro('0.500000')))->toBe(750);  // 1.5 × half-kg bag
});

it('rounds half away from zero', function () {
    expect(Qty::divRound(5, 10))->toBe(1)->and(Qty::divRound(4, 10))->toBe(0)
        ->and(Qty::divRound(-5, 10))->toBe(-1)->and(Qty::divRound(15, 10))->toBe(2);
});

it('parses and formats money by currency exponent', function () {
    expect(Money::toMinor('12.5', 'GHS'))->toBe(1250)
        ->and(Money::toMinor('1,234.56', 'GHS'))->toBe(123456)
        ->and(Money::toMinor('500', 'XOF'))->toBe(500)
        ->and(Money::format(123456, 'GHS'))->toBe('1234.56')
        ->and(Money::display(123456, 'GHS'))->toBe('GH₵ 1,234.56');
    expect(fn () => Money::toMinor('1.234', 'GHS'))->toThrow(DomainException::class);
    expect(fn () => Money::toMinor('1.5', 'XOF'))->toThrow(DomainException::class);
});

it('splits a tax-inclusive price exactly', function () use ($gh) {
    $t = TaxCalculator::line(1000, $gh, inclusive: true); // GH₵10.00 at 20% combined

    expect($t['gross'])->toBe(1000)
        ->and($t['net'])->toBe(833)                          // 1000 / 1.2 = 833.33
        ->and($t['tax'])->toBe(167)
        ->and(array_column($t['breakdown'], 'amount_minor'))->toBe([125, 21, 21])
        ->and(array_sum(array_column($t['breakdown'], 'amount_minor')))->toBe($t['tax']);
});

it('adds tax on tax-exclusive prices', function () use ($gh) {
    $t = TaxCalculator::line(1000, $gh, inclusive: false);

    expect($t['net'])->toBe(1000)->and($t['tax'])->toBe(200)->and($t['gross'])->toBe(1200);
});

it('handles exempt items and keeps component sums exact for awkward amounts', function () use ($gh) {
    expect(TaxCalculator::line(999, [], true)['tax'])->toBe(0);

    foreach ([1, 7, 99, 333, 12345, 999999] as $gross) {
        $t = TaxCalculator::line($gross, $gh, true);
        expect($t['net'] + $t['tax'])->toBe($gross)
            ->and(array_sum(array_column($t['breakdown'], 'amount_minor')))->toBe($t['tax']);
    }
});

it('sums line breakdowns per tax code', function () use ($gh) {
    $a = TaxCalculator::line(1000, $gh, true)['breakdown'];
    $b = TaxCalculator::line(500, $gh, true)['breakdown'];

    // GH₵5.00: net 417, tax 83 → 62.25 / 10.375 / 10.375 → floors 62/10/10, the spare pesewa goes to
    // the largest remainder (NHIL and GETFund tie at .375; the earlier one, NHIL, wins).
    expect(array_column($b, 'amount_minor'))->toBe([62, 11, 10])
        ->and(array_column(TaxCalculator::sum([$a, $b]), 'amount_minor'))->toBe([125 + 62, 21 + 11, 21 + 10]);
});
