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
