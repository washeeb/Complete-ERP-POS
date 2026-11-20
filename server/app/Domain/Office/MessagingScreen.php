<?php

namespace App\Domain\Office;

use App\Domain\Messaging\Gateways;
use App\Domain\Messaging\Templates;
use App\Filament\Pages\MessagingSetup;
use App\Filament\Resources\Messages\MessageResource;
use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Models\Message;
use App\Models\MessageProvider;
use App\Models\MessageTemplate;
use Illuminate\Database\Eloquent\Builder;

/**
 * Messaging in the new back office (settings and admin, part 5 of the rewrite), as the current screens: the SMS and
 * WhatsApp provider setup (which provider, sender ID, on or off, last test; the e-mail server in use), the message
 * templates (wording, on or off, the approved WhatsApp template), and the message log (what was sent, to whom, how it
 * went; Try again), through ProviderSetup, Templates and Messenger (MessagingController).
 * Saved credentials never leave the server: the screen only learns whether each one is saved.
 */
final class MessagingScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public static function canSetup(): bool
    {
        return MessagingSetup::canAccess();
    }

    public static function canTemplates(): bool
    {
        return MessageTemplateResource::canViewAny();
    }

    public static function canLog(): bool
    {
        return MessageResource::canViewAny();
    }

    private static function pairs(array $a): array
    {
        return array_map(fn ($k, $v) => ['id' => (string) $k, 'label' => (string) $v], array_keys($a), $a);
    }

    /** What is set up, with each credential only as saved or not. */
    public function setup(): array
    {
        $sms = MessageProvider::where('channel', 'sms')->first();
        $wa = MessageProvider::where('channel', 'whatsapp')->first();
        $saved = fn (?MessageProvider $p, string $provider) => $p && $p->provider === $provider
            ? array_map(fn ($v) => trim((string) $v) !== '', array_intersect_key($p->credentials ?? [], Gateways::FIELDS[$provider])) : [];
        $tested = fn (?MessageProvider $p) => $p?->tested_at ? 'Last test: '.$p->tested_at->format('j M H:i').' — '.$p->test_result : null;

        return [
            'sms' => ['providers' => self::pairs(Gateways::SMS), 'provider' => $sms?->provider ?? 'hubtel', 'sender_id' => $sms?->sender_id, 'active' => (bool) ($sms?->is_active ?? false),
                'fields' => collect(Gateways::FIELDS)->except('meta')->map(fn ($list, $provider) => array_map(fn ($k, $l) => ['key' => $k, 'label' => $l, 'secret' => $k !== 'url',
                    'saved' => (bool) ($saved($sms, $provider)[$k] ?? false)], array_keys($list), $list))->all(),
                'tested' => $tested($sms)],
            'whatsapp' => ['active' => (bool) ($wa?->is_active ?? false), 'exists' => $wa !== null,
                'fields' => array_map(fn ($k, $l) => ['key' => $k, 'label' => $l, 'secret' => $k !== 'phone_number_id', 'saved' => (bool) ($saved($wa, 'meta')[$k] ?? false)],
                    array_keys(Gateways::FIELDS['meta']), Gateways::FIELDS['meta']),
                // Typed at Meta so incoming messages reach this server: shown, as on the current page.
                'webhook' => $wa && ! empty($wa->credentials['verify_token']) ? ['url' => route('whatsapp.webhook', ['provider' => $wa->id]), 'verify_token' => $wa->credentials['verify_token']] : null,
                'tested' => $tested($wa)],
            'email' => ['driver' => (string) config('mail.default'), 'from' => (string) config('mail.from.address'), 'log_only' => config('mail.default') === 'log'],
        ];
    }

    public static function templatesQuery(): Builder
    {
        return MessageTemplateResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  q, channel, show (in use | archived | both) */
    public function templates(array $p): array
    {
        $q = self::templatesQuery();
        if (isset(Message::CHANNELS[$p['channel'] ?? ''])) {
            $q->where('channel', $p['channel']);
        }
        match ($p['show'] ?? '') {
            'archived' => $q->whereNotNull('archived_at'),
            'both' => null,
            default => $q->whereNull('archived_at'),
        };
        $search = mb_strtolower(trim((string) ($p['q'] ?? '')));

        return ['channels' => self::pairs(Message::CHANNELS), 'can_edit' => self::canTemplates(),
            'rows' => $q->orderBy('key')->orderBy('channel')->get()->map(fn (MessageTemplate $t) => [
                'id' => $t->id, 'key' => $t->key, 'name' => Templates::DEFAULTS[$t->key][0] ?? $t->key, 'placeholders' => Templates::DEFAULTS[$t->key][1] ?? null,
                'channel' => $t->channel, 'channel_label' => Message::CHANNELS[$t->channel] ?? $t->channel, 'subject' => $t->subject, 'body' => $t->body, 'active' => (bool) $t->is_active,
                'whatsapp_template' => $t->whatsapp_template, 'whatsapp_language' => $t->whatsapp_language, 'archived' => $t->archived_at !== null,
            ])->filter(fn ($r) => $search === '' || str_contains(mb_strtolower($r['name'].' '.$r['body'].' '.$r['key']), $search))->values()->all()];
    }

    /** @param  array<string, mixed>  $p  q (to or reference), status, channel, page, per */
    public function log(array $p): array
    {
        $per = in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25;
        $page = max(1, (int) ($p['page'] ?? 1));
        $q = Message::query();
        if (isset(Message::STATUSES[$p['status'] ?? ''])) {
            $q->where('status', $p['status']);
        }
        if (isset(Message::CHANNELS[$p['channel'] ?? ''])) {
            $q->where('channel', $p['channel']);
        }
        $search = trim((string) ($p['q'] ?? ''));
        if ($search !== '') {
            $q->where(fn ($w) => $w->where('to', 'ilike', "%{$search}%")->orWhere('reference', 'ilike', "%{$search}%"));
        }

        return ['total' => (clone $q)->count(), 'page' => $page, 'per' => $per, 'statuses' => self::pairs(Message::STATUSES), 'channels' => self::pairs(Message::CHANNELS),
            'rows' => $q->orderByDesc('created_at')->orderByDesc('id')->forPage($page, $per)->get()->map(fn (Message $m) => self::messageOf($m))->all()];
    }

    public static function messageOf(Message $m): array
    {
        return ['id' => $m->id, 'when' => $m->created_at?->toIso8601String(), 'channel' => Message::CHANNELS[$m->channel] ?? $m->channel, 'to' => $m->to, 'reference' => $m->reference,
            'subject' => $m->subject, 'body' => $m->body, 'status' => $m->status, 'status_label' => Message::STATUSES[$m->status] ?? $m->status, 'error' => $m->error,
            'provider' => $m->provider, 'provider_ref' => $m->provider_ref, 'attempts' => (int) $m->attempts, 'attachment' => $m->attachment_name,
            'can_retry' => in_array($m->status, ['failed', 'not_configured'], true)];
    }
}
