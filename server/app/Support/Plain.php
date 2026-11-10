<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Technical names in plain words, for the people who read them (owner 2026-11-09: "po_approve_minor: 2000000" on a
 * user's access; "fix it anywhere technical words appear"). Stored values stay as they are: amounts in pesewas, rates
 * in hundredths of a percent; only what is shown changes.
 */
final class Plain
{
    /** Role limits: what each allows, in words. A figure this large means no limit. */
    public const LIMITS = [
        'po_approve_minor' => ['money', 'Approves orders up to'],
        'expense_approve_minor' => ['money', 'Approves expenses up to'],
        'credit_override_minor' => ['money', 'Lets a customer go over their credit by up to'],
        'discount_bp' => ['percent', 'Gives discounts up to'],
    ];

    private const NO_LIMIT_MINOR = 100_000_000_000; // GH₵ 1,000,000,000 or more: the owner's "no limit"

    /** "Approves orders up to GH₵ 20,000 · Gives discounts up to 25%"; "—" when none. */
    public static function limits(?array $limits, string $currency = 'GHS'): string
    {
        $parts = [];
        foreach ((array) $limits as $key => $value) {
            [$kind, $words] = self::LIMITS[$key] ?? [null, Str::of((string) $key)->replace('_', ' ')->ucfirst()];
            $parts[] = match ($kind) {
                'money' => (int) $value >= self::NO_LIMIT_MINOR ? "{$words} any amount" : $words.' '.Money::display((int) $value, $currency),
                'percent' => $words.' '.rtrim(rtrim(number_format(((int) $value) / 100, 2), '0'), '.').'%',
                default => "{$words}: {$value}",
            };
        }

        return $parts === [] ? '—' : implode(' · ', $parts);
    }

    /** A field name as a heading: "price_list_id" → "Price list", "po_approve_minor" → "Approves orders up to". */
    public static function field(string $key): string
    {
        if (isset(self::LIMITS[$key])) {
            return self::LIMITS[$key][1];
        }
        $k = preg_replace('/_(id|minor|bp|at)$/', '', $key);

        return (string) Str::of($k)->replace(['_', '.'], ' ')->ucfirst();
    }

    /** A stored value as words: amounts in money, rates in percent, true/false as Yes/No, nothing as "—". */
    public static function value(string $key, mixed $value, string $currency = 'GHS'): string
    {
        return match (true) {
            $value === null || $value === '' => '—',
            is_bool($value) => $value ? 'Yes' : 'No',
            str_ends_with($key, '_minor') && is_numeric($value) => Money::display((int) $value, $currency),
            str_ends_with($key, '_bp') && is_numeric($value) => rtrim(rtrim(number_format(((int) $value) / 100, 2), '0'), '.').'%',
            is_array($value) => $value === [] ? '—' : (array_is_list($value) ? implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE), $value))
                : self::limits($value, $currency)),
            default => (string) $value,
        };
    }

    /** "stock_cost_run.updated" → "Stock cost run · updated". */
    public static function action(string $action): string
    {
        $parts = explode('.', $action);
        $what = (string) Str::of(array_shift($parts))->replace('_', ' ')->ucfirst();

        return $parts === [] ? $what : $what.' · '.str_replace('_', ' ', implode(' ', $parts));
    }

    /** "App\Models\StockCostRun" or "stock_cost_run" → "Stock cost run". */
    public static function record(?string $type): string
    {
        return $type ? (string) Str::of(class_basename($type))->snake()->replace('_', ' ')->ucfirst() : '—';
    }

    /** Where something was done from. */
    public static function source(?string $source): string
    {
        return ['web' => 'Back office', 'api' => 'Connected app', 'pos' => 'Till', 'cli' => 'Server', 'system' => 'Automatic', 'sync' => 'Till (sent later)'][$source ?? ''] ?? (string) $source;
    }
}
