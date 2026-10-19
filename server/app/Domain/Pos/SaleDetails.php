<?php

namespace App\Domain\Pos;

use App\Domain\Customers\CustomerService;
use App\Domain\Support\DomainException;
use App\Models\Branch;
use App\Models\Customer;
use App\Support\Settings;

/**
 * What the till records about a sale besides its lines: a comment (printed on the receipt if asked), a walk-in's name
 * and phone (no customer record needed), and industry details — a pharmacy patient's age, sex, weight and pregnancy,
 * a restaurant's guests, a service's next visit. A branch can require a name and/or phone before payment
 * (Settings → Tills and cash, per branch).
 */
final class SaleDetails
{
    /** Industry details the till may send: key => [label, type, rule]. */
    public const EXTRAS = [
        'age' => ['Age', 'int', [0, 130]],
        'sex' => ['Sex', 'choice', ['female', 'male']],
        'weight_kg' => ['Weight (kg)', 'decimal', [0, 400]],
        'pregnant' => ['Pregnant or breastfeeding', 'bool', null],
        'guests' => ['Guests', 'int', [1, 99]],
        'next_visit' => ['Next visit', 'date', null],
    ];

    public const REQUIRE = ['none' => 'Nothing (walk-ins welcome)', 'phone' => 'A phone number', 'name' => 'A name', 'name_phone' => 'A name and a phone number'];

    /**
     * @param  list<array{0: string, 1: string}>  $flags  review items for offline sales that miss what the branch requires
     * @return array{comment: ?string, comment_on_receipt: bool, walk_in_name: ?string, walk_in_phone: ?string, extras: ?array}
     */
    public static function from(array $p, ?Customer $customer, Branch $branch, bool $offline, array &$flags): array
    {
        $comment = mb_substr(trim((string) ($p['comment'] ?? '')), 0, 500) ?: null;
        $walk = is_array($p['walk_in'] ?? null) ? $p['walk_in'] : [];
        $name = $customer ? null : (mb_substr(trim((string) ($walk['name'] ?? '')), 0, 120) ?: null);
        $phone = $customer ? null : (trim((string) ($walk['phone'] ?? '')) !== '' ? CustomerService::normalisePhone((string) $walk['phone']) : null);
        if (! $customer && trim((string) ($walk['phone'] ?? '')) !== '' && (! $phone || strlen($phone) < 9)) {
            throw new DomainException('That phone number does not look right.', 'details_invalid');
        }
        $extras = [];
        foreach ((array) ($p['extras'] ?? []) as $k => $v) {
            if (! isset(self::EXTRAS[$k]) || $v === null || $v === '') {
                continue;
            }
            [$label, $type, $rule] = self::EXTRAS[$k];
            $extras[$k] = match ($type) {
                'int' => is_numeric($v) && (int) $v >= $rule[0] && (int) $v <= $rule[1] ? (int) $v : throw new DomainException("{$label}: between {$rule[0]} and {$rule[1]}.", 'details_invalid'),
                'decimal' => is_numeric($v) && (float) $v > $rule[0] && (float) $v <= $rule[1] ? round((float) $v, 1) : throw new DomainException("{$label}: up to {$rule[1]}.", 'details_invalid'),
                'choice' => in_array($v, $rule, true) ? $v : throw new DomainException("{$label}: choose one.", 'details_invalid'),
                'bool' => (bool) $v,
                'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : throw new DomainException("{$label}: a date.", 'details_invalid'),
            };
        }

        $need = (string) app(Settings::class)->forBranch('pos.require_details', $branch, $branch->tenant_id);
        $hasName = (bool) ($customer?->name ?? $name);
        $hasPhone = (bool) ($customer?->phone ?? $phone);
        $missing = array_filter(['name' => in_array($need, ['name', 'name_phone'], true) && ! $hasName, 'phone' => in_array($need, ['phone', 'name_phone'], true) && ! $hasPhone]);
        if ($missing) {
            $what = implode(' and ', array_keys($missing));
            if (! $offline) {
                throw new DomainException("Enter the customer's {$what} (Details) before payment.", 'details_required');
            }
            $flags[] = ['details_missing', "Sold offline without the customer's {$what}"]; // the goods have gone: reviewed, never refused
        }

        return ['comment' => $comment, 'comment_on_receipt' => $comment !== null && ! empty($p['comment_on_receipt']), 'walk_in_name' => $name, 'walk_in_phone' => $phone,
            'extras' => $extras ?: null];
    }
}
