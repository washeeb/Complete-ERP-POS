<?php

namespace App\Domain\Pricing;

use App\Support\Qty;

/**
 * Line tax in integer minor units. Mirror: pos/src/money.ts (must stay identical — the server
 * rejects a till total that differs by even one pesewa).
 *
 * Rules (documented in docs/pricing-and-tax.md; to be confirmed by the company's accountant):
 *  - line total = qty × unit price, rounded half away from zero to the minor unit;
 *  - tax-inclusive prices: net = round(gross × 10000 / (10000 + Σrates)); tax = gross − net;
 *    tax is split across components in proportion to their rates (largest remainder, ties by order);
 *  - tax-exclusive prices: each component = round(net × rate / 10000); gross = net + Σcomponents;
 *  - components are simple (no tax-on-tax); document totals are sums of line values.
 */
final class TaxCalculator
{
    /**
     * @param  list<array{code: string, name: string, rate_bp: int}>  $rates  in display order
     * @return array{gross: int, net: int, tax: int, breakdown: list<array{code: string, name: string, rate_bp: int, amount_minor: int}>}
     */
    public static function line(int $lineTotalMinor, array $rates, bool $inclusive): array
    {
        $totalBp = array_sum(array_column($rates, 'rate_bp'));
        if ($totalBp === 0) {
            return ['gross' => $lineTotalMinor, 'net' => $lineTotalMinor, 'tax' => 0, 'breakdown' => []];
        }

        if ($inclusive) {
            $gross = $lineTotalMinor;
            $net = Qty::divRound($gross * 10000, 10000 + $totalBp);
            $tax = $gross - $net;
            $amounts = self::allocate($tax, array_column($rates, 'rate_bp'));
        } else {
            $net = $lineTotalMinor;
            $amounts = array_map(fn ($r) => Qty::divRound($net * $r['rate_bp'], 10000), $rates);
            $tax = array_sum($amounts);
            $gross = $net + $tax;
        }

        $breakdown = [];
        foreach ($rates as $i => $r) {
            $breakdown[] = ['code' => $r['code'], 'name' => $r['name'], 'rate_bp' => $r['rate_bp'], 'amount_minor' => $amounts[$i]];
        }

        return ['gross' => $gross, 'net' => $net, 'tax' => $tax, 'breakdown' => $breakdown];
    }

    /** Sum line breakdowns into a document breakdown, keyed by code, in first-seen order. */
    public static function sum(array $breakdowns): array
    {
        $out = [];
        foreach ($breakdowns as $lines) {
            foreach ($lines as $b) {
                $out[$b['code']] ??= ['code' => $b['code'], 'name' => $b['name'], 'rate_bp' => $b['rate_bp'], 'amount_minor' => 0];
                $out[$b['code']]['amount_minor'] += $b['amount_minor'];
            }
        }

        return array_values($out);
    }

    /**
     * Split $total across weights so the parts sum exactly to $total.
     *
     * @param  list<int>  $weights
     * @return list<int>
     */
    public static function allocate(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        $parts = [];
        $remainders = [];
        foreach ($weights as $i => $w) {
            $parts[$i] = intdiv($total * $w, $sum);
            $remainders[$i] = ($total * $w) % $sum;
        }
        $left = $total - array_sum($parts);
        // Largest remainder first; ties go to the earlier component.
        $order = array_keys($remainders);
        usort($order, fn ($a, $b) => [$remainders[$b], $a] <=> [$remainders[$a], $b]);
        for ($k = 0; $k < $left; $k++) {
            $parts[$order[$k]]++;
        }

        return $parts;
    }
}
