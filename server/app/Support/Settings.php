<?php

namespace App\Support;

use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Setting;
use InvalidArgumentException;

/**
 * Tenant settings with defaults declared here. The admin Settings page is generated from
 * DEFINITIONS, so adding a definition is all it takes to make a setting editable.
 * Only add a setting together with the code that reads it.
 */
final class Settings
{
    public const DEFINITIONS = [
        'security.mfa_from_level' => [
            'type' => 'int', 'default' => 3, 'min' => 1, 'max' => 7, 'group' => 'Security',
            'label' => 'Require two-factor sign-in from security level',
            'help' => 'People at this level or above must set up an authenticator app before they can use the back office. Spec default: L3 (supervisors).',
        ],
        'security.pin_max_attempts' => [
            'type' => 'int', 'default' => 5, 'min' => 3, 'max' => 10, 'group' => 'Security',
            'label' => 'Wrong POS PINs before the PIN is locked',
            'help' => 'After this many wrong PINs in a row the person cannot sign in to a till by PIN until the lockout ends.',
        ],
        'security.pin_lockout_minutes' => [
            'type' => 'int', 'default' => 15, 'min' => 1, 'max' => 1440, 'group' => 'Security',
            'label' => 'POS PIN lockout (minutes)',
            'help' => 'How long a locked PIN stays locked. A manager can also reset it from the user record.',
        ],
        'security.offline_grant_hours' => [
            'type' => 'int', 'default' => 72, 'min' => 4, 'max' => 336, 'group' => 'Security',
            'label' => 'Offline till sign-in lasts (hours)',
            'help' => 'After signing in online, a person can keep using that till without the server for this long. Shorter is safer; longer suits branches with poor connections. Sales made after it runs out are still kept, and flagged for review.',
        ],
        'stock.block_negative_sales' => [
            'type' => 'bool', 'default' => false, 'group' => 'Stock',
            'label' => 'Stop sales when the system shows no stock',
            'help' => 'Off (recommended while stock counts are being set up): the till sells anyway and the shortfall shows as negative stock to investigate. On: the till refuses the sale.',
        ],
        'purchasing.over_receipt_pct' => [
            'type' => 'int', 'default' => 5, 'min' => 0, 'max' => 100, 'group' => 'Purchasing',
            'label' => 'Over-delivery accepted without a manager (%)',
            'help' => 'A delivery may exceed the ordered quantity by this much. Above it, the goods received note waits until a manager with “approve deliveries” accepts it.',
        ],
        'loyalty.enabled' => [
            'type' => 'bool', 'default' => true, 'group' => 'Loyalty',
            'label' => 'Loyalty points',
            'help' => 'Customers on a sale earn 1 point per GHS 1 paid; 100 points are worth GHS 1 at the till.',
        ],
        'loyalty.min_redeem_points' => [
            'type' => 'int', 'default' => 500, 'min' => 0, 'max' => 100000, 'group' => 'Loyalty',
            'label' => 'Points needed before they can be used',
            'help' => 'A customer can pay with points once they hold at least this many.',
        ],
        'loyalty.expiry_months' => [
            'type' => 'int', 'default' => 12, 'min' => 0, 'max' => 120, 'group' => 'Loyalty',
            'label' => 'Points expire after (months)',
            'help' => 'Points not used within this many months of being earned expire (oldest first). 0 = never.',
        ],
        'stored_value.expiry_months' => [
            'type' => 'int', 'default' => 12, 'min' => 0, 'max' => 120, 'group' => 'Gift cards',
            'label' => 'Gift cards and store credit expire after (months unused)',
            'help' => 'A balance expires when the card has not been used for this long; any use resets the clock. 0 = never.',
        ],
        'returns.days' => [
            'type' => 'int', 'default' => 30, 'min' => 0, 'max' => 365, 'group' => 'Returns',
            'label' => 'Returns accepted within (days, with receipt)',
            'help' => 'Within this many days a cashier can take goods back against the receipt and refund to the original payment. Without a receipt (or later): store credit only, with a supervisor PIN.',
        ],
        'finance.year_end_month' => [
            'type' => 'int', 'default' => 12, 'min' => 1, 'max' => 12, 'group' => 'Finance',
            'label' => 'Financial year ends in month (1–12)',
            'help' => '12 = January to December (owner decision). Set this before the first posting: months already created keep their dates.',
        ],
        'payables.match_tolerance_pct' => [
            'type' => 'int', 'default' => 2, 'min' => 0, 'max' => 100, 'group' => 'Finance',
            'label' => 'Supplier bill price difference allowed (%)',
            'help' => 'A bill whose prices differ from the purchase order by more than this (or more than the amount below, whichever is smaller) waits for a finance manager.',
        ],
        'payables.match_tolerance_minor' => [
            'type' => 'int', 'default' => 5000, 'min' => 0, 'max' => 100000000, 'group' => 'Finance',
            'label' => 'Supplier bill price difference allowed (pesewas)',
            'help' => '5000 = GHS 50.00 per bill.',
        ],
        'grocery.scale_prefixes' => [
            'type' => 'string', 'default' => '21', 'max' => 40, 'group' => 'Grocery',
            'label' => 'Scale label barcodes start with',
            'help' => 'EAN-13 labels from the scales: prefix (2 digits), the product\'s 5-digit scale code (PLU), the weight in grams (5 digits), check digit. Several prefixes: 21,22.',
        ],
        'grocery.markdown_prefix' => [
            'type' => 'string', 'default' => '29', 'max' => 2, 'group' => 'Grocery',
            'label' => 'Markdown stickers start with',
            'help' => 'First two digits of the barcodes printed on near-expiry markdown stickers. Must differ from the scale prefixes.',
        ],
        'grocery.markdown_rules' => [
            'type' => 'string', 'default' => '7:30,3:50', 'max' => 60, 'group' => 'Grocery',
            'label' => 'Suggested markdowns (days left : % off)',
            'help' => '"7:30,3:50" = 30 % off when 7 days or fewer are left, 50 % at 3 days. A manager applies them.',
        ],
        'grocery.esl_webhook_url' => [
            'type' => 'string', 'default' => '', 'max' => 255, 'group' => 'Grocery',
            'label' => 'Electronic shelf labels: price update address',
            'help' => 'If you use electronic shelf labels, their gateway\'s web address. Price changes are sent there as they happen. Leave blank if you do not.',
        ],
        'receipt.footer' => [
            'type' => 'string', 'default' => 'Thank you for shopping with us.', 'max' => 200, 'group' => 'Receipts',
            'label' => 'Message at the bottom of receipts',
            'help' => 'Printed on every till receipt, e.g. returns policy or a thank-you line.',
        ],
        'security.device_enrollment_hours' => [
            'type' => 'int', 'default' => 24, 'min' => 1, 'max' => 168, 'group' => 'Devices',
            'label' => 'Device enrolment code valid for (hours)',
            'help' => 'A new till must be enrolled with its one-time code within this time, or the code must be re-issued.',
        ],
    ];

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct(private readonly Recorder $recorder) {}

    public function get(string $key, ?string $tenantId): mixed
    {
        $def = self::DEFINITIONS[$key] ?? throw new InvalidArgumentException("Unknown setting [{$key}]");
        if (! $tenantId) {
            return $def['default'];
        }
        $this->cache[$tenantId] ??= Setting::withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('value', 'key')->all();

        return $this->cache[$tenantId][$key] ?? $def['default'];
    }

    public function set(Actor $actor, string $key, mixed $value, ?string $reason = null): void
    {
        $def = self::DEFINITIONS[$key] ?? throw new InvalidArgumentException("Unknown setting [{$key}]");
        if ($def['type'] === 'int') {
            $value = (int) $value;
            if ($value < $def['min'] || $value > $def['max']) {
                throw new DomainException("{$def['label']} must be between {$def['min']} and {$def['max']}.");
            }
        } elseif ($def['type'] === 'bool') {
            $value = (bool) $value;
        } elseif ($def['type'] === 'string') {
            $value = trim((string) $value);
            if (mb_strlen($value) > $def['max']) {
                throw new DomainException("{$def['label']} can be at most {$def['max']} characters.");
            }
        }

        $setting = Setting::withoutGlobalScopes()->firstOrNew(['tenant_id' => $actor->tenantId, 'key' => $key]);
        $setting->tenant_id = $actor->tenantId;
        $setting->value = $value;
        $this->recorder->save($actor, $setting, 'setting.changed', $reason);
        unset($this->cache[$actor->tenantId]);
    }
}
