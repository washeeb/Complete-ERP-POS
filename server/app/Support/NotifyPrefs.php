<?php

namespace App\Support;

use App\Models\User;

/**
 * Which notifications reach a person, and how (Profile → Notifications; interface stage 1b). Each topic lists only the
 * channels its senders really use; the company decides whether a channel is used at all (System → Settings), the
 * person may then turn a topic or a channel off for themselves. Nothing chosen = everything the company sends.
 * Security notices (SyncLytics support signing in or being added) cannot be turned off.
 */
final class NotifyPrefs
{
    public const CHANNELS = ['in_app' => 'In the back office (the bell)', 'email' => 'E-mail', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'];

    /** topic => [title, what it covers, channels its senders use] */
    public const TOPICS = [
        'approvals' => ['Waiting for your approval', 'Orders, stock adjustments, expense claims, leave and access requests waiting for you.', ['in_app']],
        'purchasing' => ['Buying', 'Purchase requests, quotes from suppliers, their confirmations and invoices, goods on the way, contracts ending.', ['in_app']],
        'stock' => ['Stock', 'Products at or below their minimum.', ['in_app']],
        'pricing' => ['Prices to review', 'New prices worked out by the pricing rules, waiting for your approval.', ['in_app']],
        'iot' => ['Sensor alarms', 'A fridge, freezer or room out of range, power off, a door left open, a sensor gone silent.', ['in_app', 'whatsapp', 'sms']],
        'service' => ['Service desk and maintenance', 'Tickets for you and their deadlines, late tickets, work orders and breakdowns.', ['in_app']],
        'projects' => ['Projects', 'Tasks given to you, your time approved or sent back.', ['in_app']],
        'crm' => ['Customers and deals (CRM)', 'Follow-ups due, leads and deals given to you.', ['in_app']],
        'cash' => ['Cash', 'A till holding more cash than allowed (cash drop needed).', ['in_app']],
        'pharmacy' => ['Pharmacy', 'Medicine sales to sign off.', ['in_app']],
        'payments' => ['Payments received', 'Mobile money and card payments as they come in, and their summary.', ['in_app', 'email', 'sms', 'whatsapp']],
        'summary' => ['Daily summary', 'The day\'s figures, once a day.', ['in_app', 'email', 'whatsapp']],
        'system' => ['Server and devices', 'The server needing attention, a till or device with a problem.', ['in_app']],
    ];

    /** Always sent, whatever the person chose. */
    public const LOCKED = ['security'];

    public static function wants(User $user, string $topic, string $channel = 'in_app'): bool
    {
        if (in_array($topic, self::LOCKED, true) || ! isset(self::TOPICS[$topic])) {
            return true;
        }

        return (bool) (($user->notify_prefs ?? [])[$topic][$channel] ?? true);
    }

    /** The choices a profile form sent, kept to known topics and their own channels. */
    public static function clean(array $in): array
    {
        $out = [];
        foreach (self::TOPICS as $topic => [, , $channels]) {
            foreach ($channels as $channel) {
                $out[$topic][$channel] = (bool) ($in[$topic][$channel] ?? true);
            }
        }

        return $out;
    }
}
