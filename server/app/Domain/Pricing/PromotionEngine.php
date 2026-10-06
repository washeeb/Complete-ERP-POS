<?php

namespace App\Domain\Pricing;

use App\Support\Qty;

/**
 * The promotion engine. MUST stay identical to pos/src/promotions.ts — the till computes, the server recomputes and
 * refuses an online sale that differs by a pesewa. Both run spec/vectors/promotions.json. Integer maths only.
 *
 * Owner decision (2026-10-11): each item gets the single best item promotion; then the best "spend X" basket offer,
 * one coupon and a manual sale discount apply on top, in that order.
 */
final class PromotionEngine
{
    public const ITEM_TYPES = ['percent', 'amount_off', 'buy_x_get_y', 'bundle'];

    public const TYPES = [
        'percent' => '% off items', 'amount_off' => 'Amount off each item', 'buy_x_get_y' => 'Buy X get Y (free or % off)',
        'bundle' => 'Bundle: N items for a price (mix & match)', 'threshold' => 'Spend X, save (basket)', 'coupon' => 'Coupon (basket)',
    ];

    /**
     * @param array{lines: list<array{key: string, product_id: string, category_id: ?string, qty_milli: int, unit_price_minor: int,
     *   tax: list<array{code: string, name: string, rate_bp: int}>, manual?: ?array{bp?: int, price_minor?: int}}>,
     *   inclusive: bool, promotions: list<array<string, mixed>>, coupon?: ?string, sale_discount_bp?: int} $input
     */
    public static function compute(array $input): array
    {
        $lines = array_values($input['lines']);
        $list = [];
        foreach ($lines as $l) {
            $list[$l['key']] = Qty::divRound($l['qty_milli'] * $l['unit_price_minor'], 1000);
        }
        $promos = array_values($input['promotions']);
        usort($promos, fn ($a, $b) => strcmp($a['id'], $b['id']));
        $overridden = fn (array $l) => isset($l['manual']['price_minor']);

        // 1. Item promotions: each line takes the single promotion that saves it most.
        $itemPromos = array_values(array_filter($promos, fn ($p) => in_array($p['type'], self::ITEM_TYPES, true) && empty($p['coupon_code'])));
        $first = array_map(fn ($p) => self::attribute($p, array_values(array_filter($lines, fn ($l) => ! $overridden($l) && self::qualifies($p, $l))), $list), $itemPromos);
        $chosen = [];
        foreach ($lines as $l) {
            $best = -1;
            $bestSaving = 0;
            foreach ($first as $i => $m) {
                $s = $m[$l['key']] ?? 0;
                if ($s > $bestSaving) {
                    $best = $i;
                    $bestSaving = $s;
                }
            }
            if ($best >= 0) {
                $chosen[$l['key']] = $best;
            }
        }
        $item = [];
        $applied = array_fill_keys(array_column($lines, 'key'), []);
        foreach ($itemPromos as $i => $p) {
            $mine = array_values(array_filter($lines, fn ($l) => ($chosen[$l['key']] ?? null) === $i));
            if (! $mine) {
                continue;
            }
            foreach (self::attribute($p, $mine, $list) as $k => $s) { // group offers recomputed on the lines that chose them
                if ($s <= 0) {
                    continue;
                }
                $item[$k] = ($item[$k] ?? 0) + $s;
                $applied[$k][] = ['id' => $p['id'], 'name' => $p['name'], 'saving_minor' => $s];
            }
        }

        // 2. Manual line discount: % off what is left, or a price override.
        $manual = [];
        foreach ($lines as $l) {
            if ($overridden($l)) {
                $manual[$l['key']] = max(0, $list[$l['key']] - Qty::divRound($l['qty_milli'] * (int) $l['manual']['price_minor'], 1000));
            } elseif (! empty($l['manual']['bp'])) {
                $manual[$l['key']] = Qty::divRound(($list[$l['key']] - ($item[$l['key']] ?? 0)) * (int) $l['manual']['bp'], 10000);
            }
        }
        $remaining = [];
        foreach ($lines as $l) {
            $remaining[$l['key']] = $list[$l['key']] - ($item[$l['key']] ?? 0) - ($manual[$l['key']] ?? 0);
        }
        $basket = [];
        $spread = function (int $amount, array $keys, ?array $p = null) use (&$remaining, &$basket, &$applied) {
            $weights = array_map(fn ($k) => $remaining[$k], $keys);
            if ($amount <= 0 || array_sum($weights) <= 0) {
                return;
            }
            foreach (TaxCalculator::allocate($amount, $weights) as $i => $s) {
                $k = $keys[$i];
                $basket[$k] = ($basket[$k] ?? 0) + $s;
                $remaining[$k] -= $s;
                if ($p && $s > 0) {
                    $applied[$k][] = ['id' => $p['id'], 'name' => $p['name'], 'saving_minor' => $s];
                }
            }
        };
        $basketValue = function (array $p, array $keys) use (&$remaining): int {
            $spend = array_sum(array_map(fn ($k) => $remaining[$k], $keys));
            if (! $keys || $spend < (int) $p['min_spend_minor'] || $spend <= 0) {
                return 0;
            }

            return (int) $p['value_bp'] > 0 ? Qty::divRound($spend * (int) $p['value_bp'], 10000) : min((int) $p['value_minor'], $spend);
        };
        $scope = fn (array $p) => array_values(array_map(fn ($l) => $l['key'], array_filter($lines, fn ($l) => self::qualifies($p, $l))));

        // 3. The best "spend X" offer.
        $bestT = null;
        $bestTV = 0;
        foreach ($promos as $p) {
            if ($p['type'] !== 'threshold' || ! empty($p['coupon_code'])) {
                continue;
            }
            $v = $basketValue($p, $scope($p));
            if ($v > $bestTV) {
                $bestT = $p;
                $bestTV = $v;
            }
        }
        if ($bestT) {
            $spread($bestTV, $scope($bestT), $bestT);
        }

        // 4. One coupon.
        $couponApplied = $couponError = null;
        $code = strtoupper(trim((string) ($input['coupon'] ?? '')));
        if ($code !== '') {
            $c = null;
            foreach ($promos as $p) {
                if (! empty($p['coupon_code']) && strtoupper($p['coupon_code']) === $code) {
                    $c = $p;
                    break;
                }
            }
            if (! $c) {
                $couponError = 'This coupon is not valid now.';
            } else {
                $v = $basketValue($c, $scope($c));
                if ($v <= 0) {
                    $couponError = (int) $c['min_spend_minor'] > 0 ? 'The basket does not reach the coupon’s minimum spend.' : 'Nothing in the basket qualifies for this coupon.';
                } else {
                    $spread($v, $scope($c), $c);
                    $couponApplied = $c['id'];
                }
            }
        }

        // 5. Manual discount on the whole sale.
        $saleBp = (int) ($input['sale_discount_bp'] ?? 0);
        if ($saleBp > 0) {
            $keys = array_column($lines, 'key');
            $spread(Qty::divRound(array_sum(array_map(fn ($k) => $remaining[$k], $keys)) * $saleBp, 10000), $keys);
        }

        $out = [];
        foreach ($lines as $l) {
            $k = $l['key'];
            $t = TaxCalculator::line($remaining[$k], $l['tax'], (bool) $input['inclusive']);
            $itemD = $item[$k] ?? 0;
            $manualD = $manual[$k] ?? 0;
            $basketD = $basket[$k] ?? 0;
            $out[] = ['key' => $k, 'list_total' => $list[$k], 'item_discount' => $itemD, 'manual_discount' => $manualD, 'basket_discount' => $basketD,
                'discount' => $itemD + $manualD + $basketD, 'total' => $t['gross'], 'net' => $t['net'], 'tax' => $t['tax'], 'breakdown' => $t['breakdown'],
                'promotions' => $applied[$k]];
        }

        return ['lines' => $out, 'net' => array_sum(array_column($out, 'net')), 'tax' => array_sum(array_column($out, 'tax')),
            'total' => array_sum(array_column($out, 'total')), 'discount' => array_sum(array_column($out, 'discount')),
            'breakdown' => TaxCalculator::sum(array_column($out, 'breakdown')), 'coupon_applied' => $couponApplied, 'coupon_error' => $couponError];
    }

    private static function qualifies(array $p, array $l): bool
    {
        if (empty($p['product_ids']) && empty($p['category_ids'])) {
            return true;
        }

        return in_array($l['product_id'], $p['product_ids'] ?? [], true) || ($l['category_id'] !== null && in_array($l['category_id'], $p['category_ids'] ?? [], true));
    }

    /** @return array<string, int> saving per line key */
    private static function attribute(array $p, array $lines, array $list): array
    {
        $out = [];
        $add = function (string $k, int $v) use (&$out) {
            $out[$k] = ($out[$k] ?? 0) + $v;
        };
        if ($p['type'] === 'percent') {
            foreach ($lines as $l) {
                $add($l['key'], Qty::divRound($list[$l['key']] * (int) $p['value_bp'], 10000));
            }
        }
        if ($p['type'] === 'amount_off') {
            foreach ($lines as $l) {
                $add($l['key'], min($list[$l['key']], (int) $p['value_minor'] * intdiv($l['qty_milli'], 1000)));
            }
        }
        if ($p['type'] === 'buy_x_get_y' || $p['type'] === 'bundle') {
            $pool = []; // whole units, most expensive first (ties: earlier line first)
            foreach ($lines as $idx => $l) {
                for ($i = 0; $i < intdiv($l['qty_milli'], 1000); $i++) {
                    $pool[] = ['key' => $l['key'], 'price' => (int) $l['unit_price_minor'], 'idx' => $idx];
                }
            }
            usort($pool, fn ($a, $b) => ($b['price'] <=> $a['price']) ?: ($a['idx'] <=> $b['idx']));
            if ($p['type'] === 'buy_x_get_y') {
                // Groups of X+Y from the most expensive; in each group the Y cheapest are discounted. The saving is shared by
                // every unit in the group (by price), so each line sees its part of the deal when choosing its best promotion.
                $size = (int) $p['buy_qty'] + (int) $p['get_qty'];
                $bp = (int) $p['value_bp'] > 0 ? (int) $p['value_bp'] : 10000;
                if ((int) $p['buy_qty'] > 0 && (int) $p['get_qty'] > 0) {
                    for ($g = 0; $g + $size <= count($pool); $g += $size) {
                        $group = array_slice($pool, $g, $size);
                        $saving = array_sum(array_map(fn ($u) => Qty::divRound($u['price'] * $bp, 10000), array_slice($group, (int) $p['buy_qty'])));
                        if ($saving > 0) {
                            foreach (TaxCalculator::allocate($saving, array_column($group, 'price')) as $i => $s) {
                                $add($group[$i]['key'], $s);
                            }
                        }
                    }
                }
            } elseif ((int) $p['bundle_qty'] > 0) {
                $n = (int) $p['bundle_qty'];
                for ($g = 0; $g + $n <= count($pool); $g += $n) {
                    $group = array_slice($pool, $g, $n);
                    $saving = max(0, array_sum(array_column($group, 'price')) - (int) $p['value_minor']);
                    if ($saving > 0) {
                        foreach (TaxCalculator::allocate($saving, array_column($group, 'price')) as $i => $s) {
                            $add($group[$i]['key'], $s);
                        }
                    }
                }
            }
        }
        foreach ($out as $k => $v) {
            $out[$k] = min($v, $list[$k]);
        }

        return $out;
    }
}
