<?php

use App\Domain\Pricing\PromotionEngine;

// The same vectors the till engine must pass (pos/src/promotions.test.ts).
$spec = json_decode(file_get_contents(dirname(__DIR__, 3).'/spec/vectors/promotions.json'), true);

foreach ($spec['cases'] as $case) {
    it($case['name'], function () use ($case, $spec) {
        $input = $case['input'];
        $input['lines'] = array_map(fn ($l) => $l + $spec['_defaults']['line'], $input['lines']);
        $input['promotions'] = array_map(fn ($p) => $p + $spec['_defaults']['promotion'], $input['promotions']);
        $r = PromotionEngine::compute($input);
        $e = $case['expect'];

        expect($r['total'])->toBe($e['total'])->and($r['discount'])->toBe($e['discount'])
            ->and(array_column($r['lines'], 'discount', 'key'))->toBe($e['lines']);
        if (isset($e['net'])) {
            expect($r['net'])->toBe($e['net'])->and($r['tax'])->toBe($e['tax']);
        }
        if (isset($e['breakdown'])) {
            expect(array_column($r['breakdown'], 'amount_minor', 'code'))->toBe($e['breakdown']);
        }
        if (array_key_exists('coupon_applied', $e)) {
            expect($r['coupon_applied'])->toBe($e['coupon_applied']);
        }
        if (array_key_exists('coupon_error', $e)) {
            expect($r['coupon_error'])->toBe($e['coupon_error']);
        }
    });
}
