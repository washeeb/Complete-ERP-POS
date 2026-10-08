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
        'distribution.deposits_as_liability' => [
            'type' => 'bool', 'default' => false, 'group' => 'Distribution',
            'label' => 'Book container deposits as money owed to customers',
            'help' => 'Off: deposits on crates, bottles and cylinders count as sales, and empties bought back reduce sales. On: deposits are held as a liability (Container deposits held) and the containers out with customers stay an asset until they come back. Change it only at the start of a month so a month is not split between the two methods.',
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
        'pharmacy.premises_licence_no' => [
            'type' => 'string', 'default' => '', 'max' => 60, 'group' => 'Pharmacy',
            'label' => 'Pharmacy Council premises licence number',
            'help' => 'Printed on the controlled-drug register and prescription receipts.',
        ],
        'pharmacy.superintendent' => [
            'type' => 'string', 'default' => '', 'max' => 120, 'group' => 'Pharmacy',
            'label' => 'Superintendent pharmacist',
            'help' => 'Name and registration number, printed on the controlled-drug register.',
        ],
        'restaurant.service_charge_bp' => [
            'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 2500, 'group' => 'Restaurant',
            'label' => 'Service charge on table bills (basis points)',
            'help' => '1000 = 10 %. Added to table and bar-tab bills at the till as a line of the service-charge product below; 0 = none. The cashier can remove it.',
        ],
        'restaurant.service_charge_sku' => [
            'type' => 'string', 'default' => 'SERVICE', 'max' => 40, 'group' => 'Restaurant',
            'label' => 'Service-charge product (SKU)',
            'help' => 'Create a product with this SKU that does not track stock; its tax group sets the tax on the service charge.',
        ],
        'restaurant.tips_enabled' => [
            'type' => 'bool', 'default' => true, 'group' => 'Restaurant',
            'label' => 'Ask for a tip at payment',
            'help' => 'Tips are paid on top of the bill, owed to the waiter on the bill (or the cashier), and paid out with that month’s payroll.',
        ],
        'restaurant.waste_approval_minor' => [
            'type' => 'int', 'default' => 5000, 'min' => 0, 'max' => 100000000, 'group' => 'Restaurant',
            'label' => 'Kitchen waste needing a manager (pesewas)',
            'help' => '5000 = GHS 50.00. Waste worth more than this (at cost) is approved by a manager’s PIN when it is recorded.',
        ],
        'restaurant.sms_webhook_url' => [
            'type' => 'string', 'default' => '', 'max' => 255, 'group' => 'Restaurant',
            'label' => 'SMS gateway address (booking messages)',
            'help' => 'Booking confirmations and reminders are posted here as {"to", "message", "reference"} for your SMS provider to send. Leave blank for no SMS.',
        ],
        'restaurant.sms_confirmations' => [
            'type' => 'bool', 'default' => true, 'group' => 'Restaurant',
            'label' => 'Text the guest when a table is booked',
            'help' => 'Needs the SMS gateway address and the guest’s phone number.',
        ],
        'restaurant.reminder_hours' => [
            'type' => 'int', 'default' => 3, 'min' => 0, 'max' => 72, 'group' => 'Restaurant',
            'label' => 'Booking reminder (hours before)',
            'help' => 'A reminder text this many hours before the booking; 0 = no reminders.',
        ],
        'restaurant.hold_minutes' => [
            'type' => 'int', 'default' => 30, 'min' => 0, 'max' => 240, 'group' => 'Restaurant',
            'label' => 'Show a booked table as reserved from (minutes before)',
            'help' => 'On the till’s floor view; a booking more than 15 minutes late is shown as late so the table can be released.',
        ],
        'fashion.alteration_sku' => [
            'type' => 'string', 'default' => 'ALTERATION', 'max' => 40, 'group' => 'Fashion',
            'label' => 'Alteration product (SKU)',
            'help' => 'Alterations are paid at the till as this product at the agreed charge. Create it with stock tracking off; its tax group taxes the charge.',
        ],
        'stock.adjustment_approval_minor' => [
            'type' => 'int', 'default' => 50000, 'min' => 0, 'max' => 1000000000, 'group' => 'Stock',
            'label' => 'Stock adjustments above this value need a manager\'s approval (in pesewas/cents)',
            'help' => 'Worked out at average cost (increases and decreases added up). 50000 = GHS 500. 0 = every adjustment document needs approval by someone else.',
        ],
        'services.slot_minutes' => [
            'type' => 'int', 'default' => 15, 'min' => 5, 'max' => 120, 'group' => 'Services',
            'label' => 'Appointment times every (minutes)',
            'help' => 'The steps offered when booking an appointment and the rows of the staff calendar.',
        ],
        'services.day_start' => [
            'type' => 'string', 'default' => '08:00', 'max' => 5, 'group' => 'Services',
            'label' => 'Calendar day starts at (HH:MM)',
            'help' => 'The first time shown in the staff calendar and offered for bookings.',
        ],
        'services.day_end' => [
            'type' => 'string', 'default' => '20:00', 'max' => 5, 'group' => 'Services',
            'label' => 'Calendar day ends at (HH:MM)',
            'help' => 'The last time shown in the staff calendar.',
        ],
        'services.reminder_hours' => [
            'type' => 'int', 'default' => 24, 'min' => 0, 'max' => 72, 'group' => 'Services',
            'label' => 'Text an appointment reminder this many hours before (0 = never)',
            'help' => 'Sent by the SMS gateway below to customers whose phone number is on the appointment.',
        ],
        'services.sms_webhook_url' => [
            'type' => 'string', 'default' => '', 'max' => 255, 'group' => 'Services',
            'label' => 'SMS gateway address for appointment messages',
            'help' => 'Leave empty to use the restaurant one. The address of your SMS provider (or a small bridge to it) that accepts {"to", "message", "reference"}.',
        ],
        'services.sms_confirmations' => [
            'type' => 'bool', 'default' => true, 'group' => 'Services',
            'label' => 'Text a confirmation when an appointment is booked',
            'help' => 'Only when an SMS gateway is set and the appointment has a phone number.',
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
