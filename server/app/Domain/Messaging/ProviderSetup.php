<?php

namespace App\Domain\Messaging;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Message;
use App\Models\MessageProvider;
use App\Security\Authorizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Setting up how SMS and WhatsApp leave this server. Credentials are stored encrypted and never shown again (an empty
 * field keeps the saved value); the audit log records that they changed, never what they are.
 */
final class ProviderSetup
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly Messenger $messenger) {}

    /** @param array{provider: string, sender_id?: ?string, credentials?: array, is_active?: bool} $data */
    public function save(Actor $actor, string $channel, array $data): MessageProvider
    {
        $this->require($actor);
        $provider = $channel === 'whatsapp' ? 'meta' : (string) ($data['provider'] ?? '');
        if (! in_array($channel, ['sms', 'whatsapp'], true) || ($channel === 'sms' && ! array_key_exists($provider, Gateways::SMS))) {
            throw new DomainException('Choose the SMS provider.');
        }
        $row = MessageProvider::firstOrNew(['tenant_id' => $actor->tenantId, 'channel' => $channel]);
        $old = $row->exists && $row->provider === $provider ? ($row->credentials ?? []) : [];
        $cred = [];
        foreach (array_keys(Gateways::FIELDS[$provider]) as $field) {
            $value = trim((string) ($data['credentials'][$field] ?? ''));
            $cred[$field] = $value !== '' ? $value : ($old[$field] ?? '');
        }
        $active = (bool) ($data['is_active'] ?? true);
        if ($provider === 'meta') { // Meta's check that the address for incoming messages is ours (set once, shown on the page)
            $cred['verify_token'] = $old['verify_token'] ?? Str::random(32);
        }
        $required = array_diff(array_keys(Gateways::FIELDS[$provider]), ['secret', 'app_secret']); // optional: only to receive
        if ($active && array_filter($required, fn ($f) => $cred[$f] === '')) {
            throw new DomainException('Fill in '.implode(', ', array_map(fn ($f) => Gateways::FIELDS[$provider][$f], $required)).'.');
        }
        if ($provider === 'webhook' && $active && ! str_starts_with($cred['url'], 'https://') && ! str_starts_with($cred['url'], 'http://')) {
            throw new DomainException('The gateway address must start with https://');
        }
        $sender = trim((string) ($data['sender_id'] ?? ''));
        if ($channel === 'sms' && $provider !== 'webhook' && $active && ! preg_match('/^[A-Za-z0-9 ]{3,11}$/', $sender)) {
            throw new DomainException('The sender ID is 3 to 11 letters or digits, registered with your provider (and the NCA).');
        }

        return DB::transaction(function () use ($actor, $row, $channel, $provider, $cred, $active, $sender) {
            $row->forceFill(['tenant_id' => $actor->tenantId, 'channel' => $channel, 'provider' => $provider, 'credentials' => $cred, 'is_active' => $active,
                'sender_id' => $sender ?: null])->save();
            $this->recorder->audit->record($actor, 'message_provider.saved', $row, ['provider' => [null, $provider], 'active' => [null, $active], 'credentials' => [null, 'changed']]);

            return $row;
        });
    }

    /** Send a test message and record the result on the provider. */
    public function test(Actor $actor, string $channel, string $to): Message
    {
        $this->require($actor);
        $row = MessageProvider::where('channel', $channel)->first() ?? throw new DomainException('Save the provider first.');
        if (! $row->is_active) {
            throw new DomainException('Switch the provider on first.');
        }
        $m = $channel === 'whatsapp'
            ? $this->messenger->send($actor->tenantId, 'whatsapp', $to, 'Test message from '.config('brand.name'), null, 'TEST', $actor->name, 'hello_world')
            : $this->messenger->send($actor->tenantId, 'sms', $to, 'Test message from '.config('brand.name').': SMS works.', null, 'TEST', $actor->name);
        $row->forceFill(['tested_at' => now(), 'test_result' => $m->status === 'sent' ? 'Sent' : mb_substr((string) $m->error, 0, 250)])->save();

        return $m;
    }

    private function require(Actor $actor): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'messaging.manage')) {
            throw new AccessDenied;
        }
    }
}
