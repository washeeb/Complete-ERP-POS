<?php

namespace App\Security;

use App\Domain\Support\Actor;
use App\Support\Money;
use App\Support\Settings;

/**
 * "Confirm it's you" before money leaves (owner decision 2026-11-13, limit GH₵ 3,000 on 2026-11-15): a screen left signed in,
 * or a stolen session, must not be able to pay out, write a debt off or redirect payments. Above the limit (Settings →
 * Security), paying a supplier, an expense claim, payroll, a staff loan, moving money between accounts and a credit note
 * for returned goods; whatever the amount, a credit note with no goods back (a debt written off) and new bank or MoMo
 * details for a supplier or a member of staff. The person confirms on App\Filament\Pages\ConfirmItsYou (authenticator code,
 * passkey, a code by WhatsApp or e-mail, or their password if they have nothing else); it lasts MINUTES.
 *
 * Checked inside the domain services, so no screen can skip it; only for people at a screen (source web): the API, the
 * server and the tills have their own controls.
 */
final class Reconfirm
{
    public const SESSION_KEY = 'erp.reconfirmed_at';

    public const MINUTES = 5;

    public const SETTING = 'security.reconfirm_above_minor';

    /** Tests of other things count as confirmed (TestCase); the tests of this rule switch it off. */
    public static bool $assumeConfirmed = false;

    public static function recent(): bool
    {
        if (self::$assumeConfirmed) {
            return true;
        }
        if (! app()->bound('session') || ! session()->isStarted()) {
            return false;
        }
        $at = (int) session()->get(self::SESSION_KEY, 0);

        return $at > 0 && now()->getTimestamp() - $at <= self::MINUTES * 60;
    }

    public static function mark(): void
    {
        session()->put(self::SESSION_KEY, now()->getTimestamp());
    }

    /** Above the company's limit (or $always): the person at the screen must have confirmed it is them in the last minutes. */
    public static function require(Actor $actor, ?int $amountMinor, string $what, bool $always = false): void
    {
        if (! $actor->user || $actor->source !== 'web') {
            return;
        }
        if (! $always) {
            $limit = (int) app(Settings::class)->get(self::SETTING, $actor->tenantId);
            if ($amountMinor === null || $amountMinor <= $limit) {
                return;
            }
        }
        if (self::recent()) {
            return;
        }
        $currency = $actor->user->tenant?->currency_code ?? 'GHS';
        throw new ReconfirmNeeded($always
            ? "Confirm it's you before {$what}."
            : "Confirm it's you before {$what} above ".Money::display((int) app(Settings::class)->get(self::SETTING, $actor->tenantId), $currency).'.');
    }
}
